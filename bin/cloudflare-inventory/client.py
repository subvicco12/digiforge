#!/usr/bin/env python3
"""Bounded metadata-only Cloudflare client. No CLI URLs, writes, retries or raw output."""
import datetime
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import socket
import ssl
import stat
import time

OPERATIONS = ('TOKEN_VERIFY', 'ACCOUNTS', 'SETTINGS', 'WORKERS', 'NAMESPACES', 'SUBSCRIPTIONS')
MAX_BYTES = 1048576
ID = re.compile(r'[a-f0-9]{32}\Z')


def endpoint(operation, account=None):
    if operation not in OPERATIONS:
        raise ValueError('ENDPOINT_DENIED')
    if operation == 'TOKEN_VERIFY':
        return '/client/v4/user/tokens/verify'
    if operation == 'ACCOUNTS':
        return '/client/v4/accounts?page=1&per_page=5'
    if not isinstance(account, str) or not ID.fullmatch(account):
        raise ValueError('ACCOUNT_DENIED')
    suffix = {'SETTINGS': 'workers/account-settings', 'WORKERS': 'workers/scripts',
              'NAMESPACES': 'workers/durable_objects/namespaces?page=1&per_page=50',
              'SUBSCRIPTIONS': 'subscriptions'}[operation]
    return '/client/v4/accounts/' + account + '/' + suffix


class HttpsTransport:
    """Fixed host; stdlib does not consult HTTP_PROXY; no redirect machinery."""
    def get(self, path, token):
        # Defense in depth if invoked outside Client: only exact generated paths.
        match = re.fullmatch(r'/client/v4/accounts/([a-f0-9]{32})/[^\r\n]+', path)
        account = match.group(1) if match else None
        if path not in [endpoint(op, account) for op in OPERATIONS
                        if op in ('TOKEN_VERIFY', 'ACCOUNTS') or account is not None]:
            raise ValueError('PATH_DENIED')
        conn = http.client.HTTPSConnection('api.cloudflare.com', timeout=10,
                                           context=ssl.create_default_context())
        try:
            conn.request('GET', path, headers={'Authorization': 'Bearer ' + token,
                         'Accept': 'application/json', 'User-Agent': 'DigiForge-readonly-inventory/1'})
            response = conn.getresponse()
            retry = response.getheader('Retry-After')
            retry = min(int(retry), 3600) if isinstance(retry, str) and re.fullmatch(r'[0-9]{1,6}', retry) else None
            return response.status, response.read(MAX_BYTES + 1), retry
        finally:
            conn.close()


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('DUPLICATE_JSON_KEY')
        result[key] = value
    return result


def digest(value):
    return hashlib.sha256(value.encode('utf-8')).hexdigest()


def bounded_integer(value):
    return type(value) is int and 0 <= value <= 1000000


def project(operation, result, info):
    """Only enums, booleans, bounded counts and digests leave this function."""
    out = {}
    if operation == 'TOKEN_VERIFY':
        if not isinstance(result, dict) or result.get('status') not in ('active', 'disabled', 'expired'):
            raise ValueError('TOKEN_SCHEMA')
        out['token_status'] = result['status']
        out['expiry'] = 'NOT_REPORTED'
        out['not_before'] = 'NOT_REPORTED'
        now = datetime.datetime.now(datetime.timezone.utc)
        for key, field in (('expires_on', 'expiry'), ('not_before', 'not_before')):
            value = result.get(key)
            if value is not None:
                if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)', value):
                    raise ValueError('TOKEN_TIME_SCHEMA')
                when = datetime.datetime.fromisoformat(value.replace('Z', '+00:00'))
                out[field] = 'IN_RANGE' if ((when > now) if key == 'expires_on' else (when <= now)) else 'OUT_OF_RANGE'
        out['policy_scopes'] = 'UNVERIFIED'
    elif operation == 'SETTINGS':
        if not isinstance(result, dict):
            raise ValueError('SETTINGS_SCHEMA')
        out['settings_observed'] = True
        out['usage_model_present'] = isinstance(result.get('default_usage_model'), str)
        out['entitlement'] = 'UNVERIFIED'
    else:
        if not isinstance(result, list) or len(result) > 10000 or any(not isinstance(row, dict) for row in result):
            raise ValueError('LIST_SCHEMA')
        if operation == 'ACCOUNTS' and (len(result) > 5 or any(not isinstance(row.get('id'), str) or not ID.fullmatch(row['id']) for row in result)):
            raise ValueError('ACCOUNT_SCHEMA')
        if operation == 'NAMESPACES' and len(result) > 50:
            raise ValueError('PAGE_SCHEMA')
        out['observed_count'] = len(result)
        out['partial'] = True  # No inference of completeness from a missing result_info.
        if isinstance(info, dict) and bounded_integer(info.get('total_count')):
            if info['total_count'] < len(result):
                raise ValueError('PAGINATION_SCHEMA')
            out['partial'] = info['total_count'] != len(result)
        out['identity_fingerprints'] = [digest(row['id']) for row in result[:50]
                                       if isinstance(row.get('id'), str) and len(row['id']) <= 128]
        if operation in ('NAMESPACES', 'WORKERS', 'SUBSCRIPTIONS'):
            out['entitlement'] = 'UNVERIFIED'
        out['available_quota'] = 'UNVERIFIED'
    return out


class Client:
    def __init__(self, token, transport=None):
        if not isinstance(token, str) or not re.fullmatch(r'[A-Za-z0-9_-]{20,256}', token):
            raise ValueError('CREDENTIAL_UNAVAILABLE')
        self._token = token
        self._transport = transport if transport is not None else HttpsTransport()
        self._calls = 0
        self._halt = None
        self._started = time.monotonic()
        self.account_ids = []  # In-memory identity selection only; never serialized.

    def call(self, operation, account=None, method='GET'):
        if method != 'GET':
            raise ValueError('METHOD_DENIED')
        path = endpoint(operation, account)
        out = {'operation': operation, 'state': 'UNKNOWN', 'http_status': None}
        if self._halt is not None:
            return dict(out, state=self._halt)
        if self._calls >= 6 or time.monotonic() - self._started >= 90:
            return dict(out, state='BUDGET_EXHAUSTED')
        self._calls += 1
        try:
            status, raw, retry = self._transport.get(path, self._token)
            if type(status) is not int or not 100 <= status <= 599:
                return dict(out, state='MALFORMED')
            out['http_status'] = status
            if status == 429:
                self._halt = 'SKIPPED_RATE_LIMIT'
                return dict(out, state='RATE_LIMITED', retry_after_seconds=retry if type(retry) is int and 0 <= retry <= 3600 else None)
            if status == 401:
                self._halt = 'SKIPPED_UNAUTHORIZED'
            error = {401: 'UNAUTHORIZED', 403: 'FORBIDDEN', 404: 'NOT_FOUND'}
            if status != 200:
                return dict(out, state=error.get(status, 'REDIRECT_DENIED' if 300 <= status < 400 else 'PROVIDER_ERROR'))
            if not isinstance(raw, bytes):
                return dict(out, state='MALFORMED')
            if len(raw) > MAX_BYTES:
                return dict(out, state='OVERSIZED')
            data = json.loads(raw, object_pairs_hook=unique_object,
                              parse_constant=lambda _: (_ for _ in ()).throw(ValueError('JSON_CONSTANT')))
            if not isinstance(data, dict) or type(data.get('success')) is not bool:
                return dict(out, state='MALFORMED')
            if data['success'] is not True:
                return dict(out, state='API_ERROR')
            result = data.get('result')
            projected = project(operation, result, data.get('result_info'))
            if operation == 'ACCOUNTS':
                self.account_ids = [row['id'] for row in result]
            return dict(out, state='OBSERVED', **projected)
        except (TimeoutError, socket.timeout):
            return dict(out, state='TIMEOUT')
        except (ValueError, TypeError, UnicodeError, RecursionError):
            return dict(out, state='MALFORMED')
        except Exception:
            return dict(out, state='TRANSPORT_ERROR')


def inventory(client, account=None):
    report = {'schema': 'digiforge-cloudflare-inventory-v1', 'connection': 'UNVERIFIED',
              'operations': [], 'effective_token_scopes': 'UNVERIFIED',
              'workers_entitlement': 'UNVERIFIED', 'durable_objects_entitlement': 'UNVERIFIED',
              'quota_usage': 'UNVERIFIED', 'billing_plan': 'UNVERIFIED',
              'dedicated_scope_feasibility': 'REQUIRES_REVIEW', 'mutations_performed': False}
    token = client.call('TOKEN_VERIFY')
    report['operations'].append(token)
    if token['state'] != 'OBSERVED' or token['token_status'] != 'active' or token['expiry'] == 'OUT_OF_RANGE' or token['not_before'] == 'OUT_OF_RANGE':
        report['connection'] = 'TOKEN_NOT_QUALIFIED'
        return report
    accounts = client.call('ACCOUNTS')
    report['operations'].append(accounts)
    if accounts['state'] != 'OBSERVED':
        report['connection'] = 'ACCOUNT_DISCOVERY_UNAVAILABLE'
        return report
    if account is None:
        report['connection'] = 'ACCOUNT_SELECTION_REQUIRED'
        return report
    if account not in client.account_ids:
        report['connection'] = 'ACCOUNT_UNAVAILABLE'
        return report
    report['selected_account_fingerprint'] = digest(account)
    for op in ('SETTINGS', 'WORKERS', 'NAMESPACES', 'SUBSCRIPTIONS'):
        report['operations'].append(client.call(op, account))
    report['connection'] = 'SCOPED_INVENTORY_OBSERVED' if all(row['state'] == 'OBSERVED' for row in report['operations']) else 'PARTIAL_INVENTORY'
    return report


def write_report(temp, report):
    directory = temp / 'digiforge-cf-inventory'
    directory.mkdir(mode=0o700, exist_ok=True)
    st = directory.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid != os.geteuid() or st.st_mode & 0o077:
        raise ValueError('REPORT_DIRECTORY_UNSAFE')
    path = directory / 'report.json'
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as stream:
        json.dump(report, stream, indent=2, allow_nan=False)
        stream.write('\n')
    return path


def main():
    try:
        token = os.environ.pop('CLOUDFLARE_READONLY_API_TOKEN', '')
        account = os.environ.get('DIGIFORGE_CF_ACCOUNT_ID') or None
        if account is not None and not ID.fullmatch(account):
            raise ValueError('ACCOUNT_DENIED')
        client = Client(token)
        report = inventory(client, account)
        encoded = json.dumps(report)
        if token in encoded:
            raise ValueError('REDACTION_FAILURE')
        write_report(Path(os.environ['RUNNER_TEMP']), report)
        print('SANITIZED_INVENTORY_WRITTEN')
        return 0
    except Exception:
        print('INVENTORY_FAILED_SAFE')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
