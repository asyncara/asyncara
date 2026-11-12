<?php
declare(strict_types=1);
$cert=file_get_contents($argv[1]);$key=file_get_contents($argv[2]);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND);
if(!$socket)throw new RuntimeException('Reservation failed');
$url='https://'.stream_socket_get_name($socket,false).'/';$accepted=[];
try{
 foreach([
  'unpaired-cert'=>['clientCertPem'=>$cert],
  'unpaired-key'=>['clientKeyPem'=>$key],
  'oversized-key'=>['clientCertPem'=>$cert,'clientKeyPem'=>str_repeat('x',65537)],
  'oversized-cert'=>['clientCertPem'=>str_repeat('x',1048577),'clientKeyPem'=>$key],
  'native-redirect'=>['clientCertPem'=>$cert,'clientKeyPem'=>$key,'followRedirects'=>true],
 ]as$name=>$options){
  try{$id=asyncara_submit('http',[$url,null,$options,1000]);}
  catch(ValueError){continue;}
  // Cancel without entering any network wait. The independently reserved
  // non-listening endpoint also rules out an unrelated server responding.
  $accepted[]=$name;asyncara_cancel($id);asyncara_poll([],[],0);
 }
 if(asyncara_info()['pendingOperations']||asyncara_info()['nativeMemoryBytes'])throw new RuntimeException('Admission leaked native state');
 if($accepted){echo json_encode(['status'=>'FAIL','accepted'=>$accepted]),"\n";exit(1);}
 echo json_encode(['status'=>'PASS','rejectedCases'=>5]),"\n";
}finally{fclose($socket);}
