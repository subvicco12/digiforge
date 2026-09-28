<?php
declare(strict_types=1);

namespace DigiForge\Integrations;

/** Stable version for a named set of keyed credential fingerprints; never includes ciphertext. */
final class CredentialEvidenceVersion
{
    public static function fromMetadata(array $rows): ?string
    {
        if ($rows===[]) return null;
        $parts=[];
        foreach ($rows as $row) {
            $name=(string)($row['secret_name']??'');
            $fingerprint=(string)($row['fingerprint']??'');
            if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/',$name)
                || !preg_match('/^[a-f0-9]{16}$/',$fingerprint)
                || isset($parts[$name])) return null;
            $parts[$name]=$name.':'.$fingerprint;
        }
        ksort($parts,SORT_STRING);
        return hash('sha256',implode("\n",$parts));
    }
}
