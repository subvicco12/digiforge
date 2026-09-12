<?php
declare(strict_types=1);
namespace DigiForge\AI;

use DigiForge\Security\Logger;

final class SchemaValidator {
    /** @return array{valid:bool,errors:list<array{path:string,code:string}>} */
    public function validate(mixed $value,array $schema,string $path='$'): array {
        $errors=[];
        $this->walk($value,$schema,$path,$errors);
        return ['valid'=>$errors===[],'errors'=>$errors];
    }
    private function walk(mixed $value,array $schema,string $path,array &$errors): void {
        $type=(string)($schema['type']??'');
        if($type!==''&&!$this->matches($value,$type)){ $errors[]=['path'=>$path,'code'=>'type']; return; }
        if(isset($schema['enum'])&&is_array($schema['enum'])&&!in_array($value,$schema['enum'],true))$errors[]=['path'=>$path,'code'=>'enum'];
        if(is_string($value)){
            $len=function_exists('mb_strlen')?mb_strlen($value):strlen($value);
            if(isset($schema['minLength'])&&$len<(int)$schema['minLength'])$errors[]=['path'=>$path,'code'=>'minLength'];
            if(isset($schema['maxLength'])&&$len>(int)$schema['maxLength'])$errors[]=['path'=>$path,'code'=>'maxLength'];
        }
        if(is_int($value)||is_float($value)){
            if(isset($schema['minimum'])&&$value<(float)$schema['minimum'])$errors[]=['path'=>$path,'code'=>'minimum'];
            if(isset($schema['maximum'])&&$value>(float)$schema['maximum'])$errors[]=['path'=>$path,'code'=>'maximum'];
        }
        if(is_array($value)&&$type==='array'){
            if(isset($schema['items'])&&is_array($schema['items']))foreach(array_values($value) as $i=>$item)$this->walk($item,$schema['items'],$path.'['.$i.']',$errors);
            return;
        }
        if(is_array($value)&&($type==='object'||$type==='')){
            foreach(array_keys($value) as $key){ if(Logger::isCredentialKey((string)$key)){$errors[]=['path'=>$path.'.'.sanitize_key((string)$key),'code'=>'credential_key'];} }
            foreach((array)($schema['required']??[]) as $required){if(!array_key_exists((string)$required,$value))$errors[]=['path'=>$path.'.'.$required,'code'=>'required'];}
            $properties=is_array($schema['properties']??null)?$schema['properties']:[];
            foreach($properties as $key=>$child){if(array_key_exists((string)$key,$value)&&is_array($child))$this->walk($value[(string)$key],$child,$path.'.'.$key,$errors);}
            if(($schema['additionalProperties']??true)===false){foreach(array_keys($value) as $key){if(!array_key_exists((string)$key,$properties))$errors[]=['path'=>$path.'.'.$key,'code'=>'additionalProperty'];}}
        }
    }
    private function matches(mixed $v,string $type): bool { return match($type){'object'=>is_array($v)&&!array_is_list($v),'array'=>is_array($v)&&array_is_list($v),'string'=>is_string($v),'integer'=>is_int($v),'number'=>is_int($v)||is_float($v),'boolean'=>is_bool($v),'null'=>$v===null,default=>false}; }
}
