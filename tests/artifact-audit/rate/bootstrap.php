<?php
// Test-only approved-context substitute, confined to this separate PHPUnit process.
namespace DigiForge\POD { final class PrintifyConnectionBoundary {public static bool $deny=false;public static function context(string $shop,int $integration,string $capability):array|\WP_Error {return self::$deny?new \WP_Error('synthetic_scope_denied'):['installation_sha256'=>str_repeat('e',64),'environment'=>'test','provider'=>'PRINTIFY','external_account_reference'=>'synthetic-account'];}} }
namespace {require __DIR__.'/../wordpress/bootstrap.php';}
