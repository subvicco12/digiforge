<?php
declare(strict_types=1);
namespace DigiForge\POD;
/** Controlled execution adapter; all network authority remains inside the transport interlock. */
final class PrintifyExecutionAdapter implements ExecutionAdapter{
 private PrintifyControlledTransport $transport;public function __construct(?PrintifyControlledTransport $transport=null){$this->transport=$transport??new PrintifyControlledTransport();}
 public function execute(array $permit,array $payload):array|\WP_Error{return $this->transport->execute($permit,$payload);}
}
