<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\CertificateService;
use App\Http\Requests\Academy\{CertificateIssueRequest,VersionedActionRequest};
use App\Http\Resources\Academy\{AcademyActionResource,CertificateResource};
final class CertificateController extends AcademyController
{
    public function store(CertificateIssueRequest $r,string $enrollment){$v=$this->service(CertificateService::class)->issue($this->actor($r),$this->session($r),$this->id('enrollments',$enrollment),$this->id('files',$r->validated('file')),$r->validated('reason'),$r->validated('override_reason'),$r->claimed());return (new CertificateResource($v))->response()->setStatusCode(201);}
    public function revoke(VersionedActionRequest $r,string $certificate){return new AcademyActionResource($this->service(CertificateService::class)->revoke($this->actor($r),$this->session($r),$this->id('certificates',$certificate),$r->validated('reason'),$r->validated('lock_version'),$r->validated('override_reason'),$r->claimed()));}
}
