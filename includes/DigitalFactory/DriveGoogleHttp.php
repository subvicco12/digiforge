<?php
declare(strict_types=1);
namespace DigiForge\DigitalFactory;
/** Internal bounded transport. No automatic retry, logging or public endpoint. */
interface DriveGoogleHttp {
    public function exchange(#[\SensitiveParameter] array $binding,string $url,#[\SensitiveParameter] array $args):array;
}
