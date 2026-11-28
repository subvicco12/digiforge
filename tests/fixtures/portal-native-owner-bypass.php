<?php
namespace { class WP_Error {} define('DIGIFORGE_FIRST_PARTY_OWNER', 'host-configured'); }
namespace DigiForge\Security { final class FirstPartyOwnerPolicy { public const CONFIG='DIGIFORGE_FIRST_PARTY_OWNER'; public static function records(){ return [['authority'=>'OWNER','actor_id'=>1]]; } } }
namespace { require $argv[1]??dirname(__DIR__,2).'/includes/Security/ShopAccessAuthority.php'; $r=\DigiForge\Security\ShopAccessAuthority::records(); exit($r instanceof WP_Error?0:1); }
