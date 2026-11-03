<?php
declare(strict_types=1);
namespace DigiForge\Research;
use WP_Error;
/** No authorized image integration is installed. No network/dependency or credential probing. */
final class DesignFactoryUnavailableProvider implements DesignGenerationProvider
{
    public function status(): array {return ['available'=>false,'reason'=>'NO_AUTHORIZED_IMAGE_PROVIDER','manual_import_available'=>true];}
    public function generate(array $specification,string $attemptKey): array|WP_Error {return new WP_Error('design_provider_unavailable','No authorized image-generation provider. Import original editable SVG and optional PNG; no call or retry was made.');}
}
