"""Installed-lineage Issue1257 composition. No source archive or production dump is published."""
import argparse
import hashlib
import json
import pathlib
import stat
import subprocess
import zipfile

BASE_DIGEST = '800a29e7bca92bcf76804747d4a23f15329b38606649d570df25e1f109fe7677'
ROOT = pathlib.Path(__file__).resolve().parent.parent
RUNTIME = ['includes/PortalAccess/' + n + '.php' for n in ['Authentication', 'Feature', 'Guard', 'Invitations', 'Limiter', 'Owner', 'Panel', 'Policy', 'Session', 'Store', 'RestPolicy', 'RestRoutes', 'OwnerReadiness', 'EmailAuthentication', 'OwnerBootstrap', 'GlobalOwnerAuthority', 'ServerOwnerApproval']] + ['assets/portal-access.css', 'assets/portal-access.js']
RUNTIME += ['includes/POD/ProductIntelligenceXlsx.php', 'includes/POD/PrintifySupplierWorkflow.php', 'includes/Portal/PrintifySupplierWorkflowPanel.php']
RUNTIME += ['includes/Research/CommercialResearchContract.php', 'includes/Research/CommercialResearchRepository.php', 'includes/Research/DesignFactoryContract.php', 'includes/Research/DesignFactoryRepository.php', 'includes/Research/DesignFactoryUnavailableProvider.php', 'includes/Research/DesignGenerationProvider.php', 'includes/Research/GovernedResearchProvider.php', 'includes/Research/OpportunityDesignContract.php', 'includes/Research/OpportunityDesignRepository.php', 'includes/Research/ResearchProvider.php', 'includes/POD/ApprovedDesignListingPackage.php', 'includes/POD/ProductPackageRepository.php', 'includes/POD/ProductPackageSpecification.php', 'includes/POD/Shop2MockupRepository.php', 'includes/POD/Shop2ProductWorkflow.php', 'includes/POD/Shop2ReviewBundle.php', 'includes/Portal/CommercialResearchPanel.php', 'includes/Portal/DesignFactoryPanel.php', 'includes/Portal/ProductPackagePanel.php', 'includes/Portal/Shop2ProductWorkflowPanel.php']
RUNTIME += ['includes/POD/PrintifyProductPipeline.php', 'includes/POD/PrintifyProductRequest.php', 'includes/POD/PrintifyProductResponse.php', 'includes/POD/Shop2EtsyDraftHandoff.php', 'includes/Portal/Shop2ProviderProductPanel.php']
TRANSPORT = 'includes/POD/PrintifyControlledTransport.php'
PRODUCT_METHOD = b' public function executeProduct(string $planHash,string $authorizationHash):array|WP_Error{return (new PrintifyProductPipeline())->execute($planHash,$authorizationHash,$this->sender);}' + b'\n'
API_OVERLAY = ['includes/POD/PrintifyCatalogReadContract.php', 'includes/POD/PrintifyResearchMapper.php', 'includes/Portal/ProductIntelligencePanel.php', 'includes/POD/PrintifyResearchDependencies.php', 'includes/POD/ScopedProviderConnection.php']
PRESERVED_SECURITY = ['includes/Security/ShopAccessAuthority.php', 'includes/Security/ShopAccessRestGuard.php', 'includes/Security/ShopAccessTargetScope.php', 'includes/Portal/ProductEvidenceScope.php', 'includes/Listings/EtsyStoredShopScope.php']
MODIFIED = ['digiforge.php', 'includes/Security/ShopAccess.php', 'includes/Security/ShopAccessAdminGuard.php', 'includes/Security/ShopAccessRepository.php']


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', type=pathlib.Path, required=True)
    p.add_argument('--output', type=pathlib.Path, required=True)
    args = p.parse_args()
    if sha(args.base.read_bytes()) != BASE_DIGEST:
        raise ValueError('Exact installed-lineage base ZIP digest mismatch')
    original = {}
    with zipfile.ZipFile(args.base) as z:
        if z.testzip() is not None or len(z.infolist()) != 692:
            raise ValueError('Base ZIP integrity or inventory mismatch')
        for i in z.infolist():
            n = pathlib.PurePosixPath(i.filename)
            if n.parts[0] != 'digiforge' or n.is_absolute() or '..' in n.parts or '\\' in i.filename or stat.S_IFMT(i.external_attr >> 16) != stat.S_IFREG:
                raise ValueError('Unsafe base package member')
            rel = str(n.relative_to('digiforge'))
            if rel in original:
                raise ValueError('Duplicate base member')
            original[rel] = z.read(i)
    head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    for rel in RUNTIME + MODIFIED + API_OVERLAY + [TRANSPORT] + PRESERVED_SECURITY + ['bin/package-portal-access.py', 'docs/engineering/printify-software-overlay.json', 'docs/engineering/portal-access/native-acl-overlay.json', 'docs/engineering/portal-access/global-owner-native-overlay.json']:
        if subprocess.check_output(['git', 'show', head + ':' + rel], cwd=ROOT) != (ROOT / rel).read_bytes():
            raise ValueError('Uncommitted overlay or builder: ' + rel)
    for rel in PRESERVED_SECURITY:
        if original[rel] != (ROOT / rel).read_bytes():
            raise ValueError('Installed security contract must remain identical: ' + rel)
    payload = dict(original)
    for rel in RUNTIME:
        if rel in original:
            raise ValueError('Feature destination already exists: ' + rel)
        payload[rel] = (ROOT / rel).read_bytes()
    # Prove the two shared edits are additions to the actual installed files.
    admission = b"$action=substr($hook,str_starts_with($hook,'admin_post_nopriv_')?18:11);"
    addition = b"\n        // Exact feature handlers enforce WP authentication, nonce and independent authority.\n        if(class_exists(\\DigiForge\\PortalAccess\\Feature::class)&&in_array($action,\\DigiForge\\PortalAccess\\Feature::ACTIONS,true))return;"
    expected_guard = original[MODIFIED[2]].replace(admission, admission + addition)
    if expected_guard != (ROOT / MODIFIED[2]).read_bytes():
        raise ValueError('Shared admin guard has an unreviewed delta')
    expected_header = original['digiforge.php'].replace(b'1.0.103.2', b'1.0.103.11') + b'\n(new \\DigiForge\\PortalAccess\\Feature())->register();\n'
    if expected_header != (ROOT / 'digiforge.php').read_bytes():
        raise ValueError('Plugin bootstrap has an unreviewed delta')
    # Native ACL source was previously byte-identical to this installed-lineage file.
    source_base = subprocess.check_output(['git', 'show', '6e521719d925c75f27aed5ccdd47048cc1e5a15d:includes/Security/ShopAccess.php'], cwd=ROOT)
    if source_base != original[MODIFIED[1]]:
        raise ValueError('Native ACL baseline identity changed')
    if subprocess.check_output(['git', 'show', '99d18f0c6fa9c07f4e8ba0681279a62f75364bd7:includes/Security/ShopAccessRepository.php'], cwd=ROOT) != original['includes/Security/ShopAccessRepository.php']:
        raise ValueError('Native repository baseline identity changed')
    for rel in MODIFIED:
        payload[rel] = (ROOT / rel).read_bytes()
    overlay = json.loads((ROOT / 'docs/engineering/printify-software-overlay.json').read_text())
    if set(overlay) != set(API_OVERLAY):
        raise ValueError('Exact API overlay inventory required')
    for rel in API_OVERLAY:
        raw = (ROOT / rel).read_bytes()
        if sha(original[rel]) != overlay[rel]['installed_sha256'] or sha(raw) != overlay[rel]['candidate_sha256']:
            raise ValueError('API overlay preimage or reviewed candidate mismatch: ' + rel)
        payload[rel] = raw
    # Preserve the installed stronger order/dispatch implementation; add only the product entry.
    anchor = b' public function execute(array $permit'
    source_preimage = subprocess.check_output(['git', 'show', '1590246db06485adb4c83f2f0e6d117f599b1020:' + TRANSPORT], cwd=ROOT)
    if source_preimage.count(anchor) != 1 or (ROOT / TRANSPORT).read_bytes() != source_preimage.replace(anchor, PRODUCT_METHOD + anchor, 1):
        raise ValueError('Only the exact additive product transport method is admitted')
    if original[TRANSPORT].count(anchor) != 1:
        raise ValueError('Installed transport entry differs')
    payload[TRANSPORT] = original[TRANSPORT].replace(anchor, PRODUCT_METHOD + anchor, 1)
    assert payload[TRANSPORT].replace(PRODUCT_METHOD, b'', 1) == original[TRANSPORT]
    args.output.mkdir(parents=True, exist_ok=True)
    target = args.output / 'digiforge-1.0.103.11-consolidated-update.zip'
    records = []
    with zipfile.ZipFile(target, 'w') as archive:
        for rel, raw in sorted(payload.items()):
            name = 'digiforge/' + rel
            i = zipfile.ZipInfo(name, (2026, 11, 6, 0, 0, 0))
            i.create_system = 3
            i.external_attr = (stat.S_IFREG | 0o644) << 16
            i.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(i, raw, compresslevel=9)
            records.append({'path': name, 'size': len(raw), 'sha256': sha(raw), 'baseline_sha256': sha(original[rel]) if rel in original else None, 'origin': 'reviewed-feature-source' if rel in RUNTIME + MODIFIED + API_OVERLAY else 'installed-baseline-plus-additive-product-method' if rel == TRANSPORT else 'exact-installed-1032', 'source_sha256': sha((ROOT / rel).read_bytes()) if (ROOT / rel).is_file() else None, 'source_byte_identical': (ROOT / rel).is_file() and raw == (ROOT / rel).read_bytes(), 'action': 'ADD' if rel not in original else 'RETAIN_IDENTICAL' if raw == original[rel] else 'MODIFY_REVIEWED'})
    counts = {a: sum(r['action'] == a for r in records) for a in ['RETAIN_IDENTICAL', 'MODIFY_REVIEWED', 'ADD']}
    if counts != {'RETAIN_IDENTICAL': 682, 'MODIFY_REVIEWED': 10, 'ADD': 47} or len(records) != 739 or not original.keys() <= payload.keys():
        raise ValueError('Preservation contract violated')
    with zipfile.ZipFile(target) as z:
        assert z.testzip() is None
        for r in records:
            assert sha(z.read(r['path'])) == r['sha256']
    manifest = {'artifact': target.name, 'sha256': sha(target.read_bytes()), 'size': target.stat().st_size, 'version': '1.0.103.11', 'source_sha': head, 'base_sha256': BASE_DIGEST, 'composition_is_exact_checkout': False, 'counts': {**counts, 'DELETE': 0}, 'installation_authorized': False, 'schema_migration': 'NONE', 'restrictive_acl_sha256': sha(payload['includes/Security/ShopAccess.php']), 'authority_sha256': sha(payload['includes/Security/ShopAccessAuthority.php']), 'files': records}
    (args.output / 'preservation-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (args.output / (target.name + '.sha256')).write_text(manifest['sha256'] + '  ' + target.name + '\n')
    print(json.dumps({k: v for k, v in manifest.items() if k != 'files'}, indent=2))


if __name__ == '__main__':
    main()
