<?php

declare(strict_types=1);

namespace DigiForge\Launch;

use DigiForge\Database\Tables;
use DigiForge\Integrations\CredentialVault;
use DigiForge\Integrations\Repository as IntegrationRepository;
use DigiForge\Security\Logger;

final class OpenAIClient
{
    private const RESPONSES_URL = 'https://api.openai.com/v1/responses';
    private const DEFAULT_MODEL = 'gpt-5.6-luna';

    public function research(string $brief): array|\WP_Error { return $this->request($brief, true, 4000); }
    public function develop(string $brief): array|\WP_Error { return $this->request($brief, false, str_starts_with($brief, 'You are DigiForge U3 Production.') ? 16000 : 4000); }

    private function request(string $brief, bool $webSearch, int $maxOutputTokens): array|\WP_Error
    {
        $connector=$this->connector(); if(is_wp_error($connector))return $connector;
        $apiKey=$this->secret((int)$connector['id'],'api_key'); if(is_wp_error($apiKey))return $apiKey;
        $body=['model'=>self::DEFAULT_MODEL,'input'=>$brief,'max_output_tokens'=>max(1000,min(16000,$maxOutputTokens)),'text'=>['format'=>['type'=>'json_object']]];
        if($webSearch)$body['tools']=[['type'=>'web_search']];
        $response=wp_remote_post(self::RESPONSES_URL,[
            'timeout' => 90,
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => true,
            'headers'=>['Authorization'=>'Bearer '.$apiKey,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode($body),
        ]); unset($apiKey);
        if(is_wp_error($response)){Logger::audit('launch_openai_request_failed',['reason'=>'transport'],'launch_execution');return new \WP_Error('digiforge_launch_ai_transport',__('AI provider request failed.','digiforge'),['status'=>502]);}
        $status=(int)wp_remote_retrieve_response_code($response);$decoded=json_decode((string)wp_remote_retrieve_body($response),true);
        if($status===429)return $this->rateLimitError($response,is_array($decoded)?$decoded:[]);
        if($status<200||$status>=300||!is_array($decoded)){Logger::audit('launch_openai_request_failed',['http_status'=>$status,'request_id'=>$this->requestId($response)],'launch_execution');return new \WP_Error('digiforge_launch_ai_provider',__('AI provider rejected the request.','digiforge'),['status'=>502]);}
        $responseStatus=sanitize_key((string)($decoded['status']??''));
        if($responseStatus==='incomplete'){
            $reason=sanitize_key((string)($decoded['incomplete_details']['reason']??'unknown'));
            Logger::audit('launch_openai_incomplete',['response_id'=>sanitize_text_field((string)($decoded['id']??'')),'reason'=>$reason,'max_output_tokens'=>max(1000,min(16000,$maxOutputTokens))],'launch_execution');
            return new \WP_Error('digiforge_launch_ai_incomplete',__('AI provider response was incomplete. Retry with a smaller structured payload.','digiforge'),['status'=>502,'reason'=>$reason]);
        }
        if($responseStatus!==''&&$responseStatus!=='completed'){
            Logger::audit('launch_openai_unexpected_status',['response_id'=>sanitize_text_field((string)($decoded['id']??'')),'response_status'=>$responseStatus],'launch_execution');
            return new \WP_Error('digiforge_launch_ai_status',__('AI provider response did not complete successfully.','digiforge'),['status'=>502]);
        }
        $text=$this->extractOutputText($decoded);if($text==='')return new \WP_Error('digiforge_launch_ai_empty',__('AI provider returned no usable text.','digiforge'),['status'=>502]);
        $payload=$this->decodeJsonObject($text);
        if(!is_array($payload)){Logger::audit('launch_openai_invalid_json',['response_id'=>sanitize_text_field((string)($decoded['id']??'')),'response_status'=>$responseStatus,'output_chars'=>strlen($text),'json_error'=>sanitize_text_field(json_last_error_msg())],'launch_execution');return new \WP_Error('digiforge_launch_ai_invalid_json',__('AI provider output was not valid structured JSON.','digiforge'),['status'=>502]);}
        return ['payload'=>$payload,'response_id'=>sanitize_text_field((string)($decoded['id']??'')),'model'=>sanitize_text_field((string)($decoded['model']??self::DEFAULT_MODEL)),'usage'=>is_array($decoded['usage']??null)?$decoded['usage']:[]];
    }

    private function rateLimitError(array $response,array $decoded):\WP_Error
    {
        $providerError=is_array($decoded['error']??null)?$decoded['error']:[];$providerCode=sanitize_key((string)($providerError['code']??''));$providerType=sanitize_key((string)($providerError['type']??''));$requestId=$this->requestId($response);$retryAfter=sanitize_text_field((string)wp_remote_retrieve_header($response,'retry-after'));$context=['http_status'=>429,'provider_code'=>$providerCode,'provider_type'=>$providerType,'request_id'=>$requestId];if($retryAfter!=='')$context['retry_after']=$retryAfter;Logger::audit('launch_openai_request_failed',$context,'launch_execution');
        if(in_array($providerCode,['insufficient_quota','billing_hard_limit_reached'],true)||$providerType==='insufficient_quota')return new \WP_Error('digiforge_launch_ai_quota',__('AI provider quota or billing availability must be restored before launch research can run.','digiforge'),['status'=>429]);
        return new \WP_Error('digiforge_launch_ai_rate_limited',__('AI provider is temporarily rate limited. Retry after the provider limit resets.','digiforge'),['status'=>429]);
    }
    private function requestId(array $response):string{return sanitize_text_field((string)wp_remote_retrieve_header($response,'x-request-id'));}
    private function connector():array|\WP_Error{global $wpdb;$row=$wpdb->get_row("SELECT * FROM ".Tables::integrations()." WHERE provider='ai' AND environment='production' AND status='CONFIGURED' AND enabled=1 ORDER BY id ASC LIMIT 1",ARRAY_A);return is_array($row)?$row:new \WP_Error('digiforge_launch_ai_connector',__('An enabled production AI connector is required.','digiforge'),['status'=>409]);}
    private function secret(int $integrationId,string $name):string|\WP_Error{global $wpdb;$ciphertext=$wpdb->get_var($wpdb->prepare('SELECT ciphertext FROM '.Tables::integration_secrets().' WHERE integration_id=%d AND secret_name=%s LIMIT 1',$integrationId,$name));if(!is_string($ciphertext)||$ciphertext==='')return new \WP_Error('digiforge_launch_ai_secret',__('AI connector credential is missing.','digiforge'),['status'=>409]);try{return CredentialVault::decrypt($ciphertext,IntegrationRepository::secretContext($integrationId,$name));}catch(\Throwable $e){return new \WP_Error('digiforge_launch_ai_secret',__('AI connector credential could not be decrypted.','digiforge'),['status'=>500]);}}
    private function extractOutputText(array $response):string{if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);$parts=[];foreach((array)($response['output']??[])as$item){if(!is_array($item))continue;foreach((array)($item['content']??[])as$content){if(is_array($content)&&isset($content['text'])&&is_string($content['text']))$parts[]=$content['text'];}}return trim(implode("\n",$parts));}
    private function decodeJsonObject(string $text):?array{$text=trim($text);$text=preg_replace('/^```(?:json)?\s*/i','',$text)??$text;$text=preg_replace('/\s*```$/','',$text)??$text;$decoded=json_decode($text,true);if(is_array($decoded))return $decoded;$start=strpos($text,'{');$end=strrpos($text,'}');if($start!==false&&$end!==false&&$end>=$start){$decoded=json_decode(substr($text,$start,$end-$start+1),true);if(is_array($decoded))return $decoded;}return null;}
}
