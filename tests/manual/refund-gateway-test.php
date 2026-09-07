<?php
declare(strict_types=1);
require (getenv('VA_PROOF_PLUGIN_ROOT')?:dirname(__DIR__,2)).'/vendor/autoload.php';
use VeciAhorra\Modules\Payments\Gateway\WebpayPaymentGateway;
use VeciAhorra\Modules\Payments\Gateway\WebpayGatewayConfiguration;
use Transbank\Webpay\WebpayPlus\Responses\TransactionRefundResponse;
$count=0;
function checkGateway(bool $ok,string $label):void{++$GLOBALS['count'];if(!$ok)throw new RuntimeException('FAIL '.$label);}
$configuration=new WebpayGatewayConfiguration('integration','597055555532',str_repeat('A',32),'https://example.test/return');
foreach([
    [['type'=>'NULLIFIED','response_code'=>0,'nullified_amount'=>9700],'refunded',false],
    [['type'=>'REVERSED'],'refunded',true],
    [['type'=>'NULLIFIED','response_code'=>-1],'refund_failed',false],
    [['type'=>'NULLIFIED','response_code'=>-1,'nullified_amount'=>9700],'refund_uncertain',false],
    [['type'=>'NULLIFIED','response_code'=>0,'nullified_amount'=>9699],'refund_uncertain',false],
    [['type'=>'NULLIFIED','response_code'=>0,'nullified_amount'=>9700.0],'refund_uncertain',false],
    [['type'=>'NULLIFIED','response_code'=>0],'refund_uncertain',false],
    [['type'=>'REVERSED','response_code'=>-1],'refund_uncertain',false],
    [[], 'refund_uncertain',false],
    [null,'refund_uncertain',false],
] as [$body,$expected,$reversed]){
    $sdk=new class($body){public int $calls=0;public function __construct(private ?array $body){}public function refund(string $token,int $amount):object{
        ++$this->calls;checkGateway($amount===9700&&$token===str_repeat('A',32),'SDK_INTEGER_TOKEN_ARGUMENTS');
        if($this->body===null)throw new RuntimeException('secret '.str_repeat('A',32));
        return new TransactionRefundResponse($this->body);
    }};
    $result=(new WebpayPaymentGateway($configuration,$sdk))->refund(str_repeat('A',32),9700);
    checkGateway($result->status===$expected&&$result->reversed===$reversed,'SDK_RESPONSE_CLASSIFICATION');
    checkGateway(!str_contains(json_encode($result),str_repeat('A',32))&&$sdk->calls===1,'SANITIZED_SINGLE_CALL');
}
$method=new ReflectionMethod(Transbank\Webpay\WebpayPlus\Transaction::class,'refund');
checkGateway(count($method->getParameters())===2,'LOCKED_SDK_REFUND_SUPPORTED');
echo "PASS refund-gateway assertions={$count} external_calls=0\n";
