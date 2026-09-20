<?php
namespace App\Http\Controllers\Api\V1\Academy;
use App\Domain\Academy\AcademicAttendanceService;
use App\Http\Requests\Academy\{AcademyListRequest,AttendanceBulkRequest,AttendanceRecordRequest};
use App\Http\Resources\Academy\{AcademyActionResource,AcademyCollectionResource};
final class AttendanceController extends AcademyController
{
    public function store(AttendanceRecordRequest $r,int $session,string $enrollment){$v=$this->service(AcademicAttendanceService::class)->record($this->actor($r),$this->session($r),$session,$this->id('enrollments',$enrollment),$r->validated('attendance_status'),$r->validated('override_reason'),$r->validated('lock_version'),$r->claimed());return new AcademyActionResource($v);}
    public function bulk(AttendanceBulkRequest $r,int $session){$entries=[];foreach($r->validated('entries') as $entry){$entries[$this->id('enrollments',$entry['enrollment'])]=$entry['attendance_status'];}$v=$this->service(AcademicAttendanceService::class)->recordBulk($this->actor($r),$this->session($r),$session,$entries,$r->validated('override_reason'),$r->claimed());return new AcademyCollectionResource($v);}
    public function index(AcademyListRequest $r,int $session){$v=$this->service(AcademicAttendanceService::class)->listForSession($this->actor($r),$this->session($r),$session,(int)($r->validated('page')??1),(int)($r->validated('per_page')??50),$r->claimed());return new AcademyCollectionResource($v);}
}
