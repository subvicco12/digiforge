<?php
declare(strict_types=1);
namespace DigiForge\Security;

final class ShopAccess
{
    public static function authorize(string $shop,string $cap,int $actorId=0): true|\WP_Error
    {
        $actor=$actorId===0?get_current_user_id():$actorId;
        $ordinary=$actor>0&&user_can($actor,$cap);
        $repository=new ShopAccessRepository();$state=$repository->read($shop);$failure=null;
        if(!($state instanceof \WP_Error)&&$ordinary){foreach($state['grants'] as $id=>$grant){
            $user=get_userdata($actor);$role=get_role($grant['role']);
            if(!$user||!$role||!in_array($grant['role'],$user->roles,true)||!$role->has_cap($cap)||!ShopAccessPolicy::permits($grant,$actor,$shop,$cap,time(),$ordinary))continue;
            $proof=$repository->verifyGrantEvidence($id,$grant,$state['revision']);if($proof instanceof \WP_Error){$failure=$proof;break;}
            $authority=ShopAccessRepository::authorityFor($grant['grantor_id'],$shop,$grant['caps'],$grant['expires_at'],null,$grant['authority_hash']);
            if(!($authority instanceof \WP_Error)&&hash_equals($grant['authority_hash'],$authority['hash'])&&user_can($grant['grantor_id'],'manage_digiforge_settings')){
                $valid=true;foreach($grant['caps'] as $assignedCap){if(!user_can($grant['grantor_id'],$assignedCap))$valid=false;}if($valid)return true;
            }
        }}
        if(!Logger::write('shop_access_denied',['actor_id'=>$actor,'shop'=>$shop,'capability'=>$cap,'privileged_actor'=>$ordinary],'shop_access',$shop))return new \WP_Error('digiforge_shop_access_audit','Shop denial audit unavailable.',['status'=>503]);
        return $failure??($state instanceof \WP_Error?$state:new \WP_Error('digiforge_shop_access_denied','No current explicit actor/shop assignment permits this operation.',['status'=>403]));
    }
    public static function allowedShops(string $cap,int $actorId=0): array|\WP_Error
    {
        $allowed=[];foreach(ShopAccessPolicy::canonicalShops() as $shop){$result=self::authorize($shop,$cap,$actorId);if($result===true)$allowed[]=$shop;elseif(($result->get_error_data()['status']??403)===503)return $result;}return $allowed;
    }
}
