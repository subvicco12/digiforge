<?php
declare(strict_types=1);
final class MasterCatalogCandidateFixture {
 public static function rows():array {
  $masters=[];$migration=[];
  for($i=1;$i<=500;$i++){
   $n=sprintf('%03d',$i);$retained=$i<=428;
   $origin=$retained?'V1 retained':($i<=458?'V2 addition':'V2 new family');
   $masters[]=['DG2-'.$n,$retained?'DG-'.$n:'','Family','Concept '.$n,'PHOTO_TEXT','PP-001','PRINTIFY_PRIMARY','TEMPLATE_RESEARCH',$origin,$retained?'Re-score':($i<=458?'Deep research':'Provider + demand research')];
   $migration[]=['DG-'.$n,'W1','Family','Concept '.$n,'PHOTO_TEXT',$retained?'KEEP':($i<=465?'MERGE':'DOWNGRADE'),'Preserve source decision',$retained?'DG2-'.$n:'','No'];
  }
  return [$masters,$migration];
 }
}
