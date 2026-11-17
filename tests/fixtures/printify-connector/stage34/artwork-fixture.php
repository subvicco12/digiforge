<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
 $prefix='DigiForgePrintifyConnector\\';
 if(str_starts_with($class,$prefix)) { $path=dirname(__DIR__,4).'/companion/digiforge-printify-connector/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path; }
});
return static function(): array {
 $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.hash('crc32b',$type.$data,true);
 $bytes="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',10,10,8,6,0,0,0)).$chunk('pHYs',pack('NNC',11811,11811,1)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\xff",40),10))).$chunk('IEND','');
 $scope=new \DigiForge\Connector\Printify\ShopBinding(2,'personalized_pod',7,'123','test',1,new \DigiForge\Connector\Printify\CredentialReference(7),str_repeat('b',64),2000000000);
 $evidence=['asset_sha256'=>hash('sha256',$bytes),'rights_receipt_sha256'=>str_repeat('a',64),'ownership_receipt_sha256'=>str_repeat('b',64),'geometry_receipt_sha256'=>str_repeat('c',64),'shop_key'=>'personalized_pod','integration_id'=>7,'provider_shop_id'=>'123','geometry'=>['width_px'=>10,'height_px'=>10,'dpi'=>300,'safe_zone'=>['x'=>2,'y'=>2,'width'=>6,'height'=>6],'bleed_px'=>1],'content_bounds'=>['x'=>3,'y'=>3,'width'=>4,'height'=>4]];
 return [$bytes,$evidence,$scope,$chunk];
};
