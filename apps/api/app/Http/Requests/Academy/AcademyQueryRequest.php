<?php

declare(strict_types=1);

namespace App\Http\Requests\Academy;

use Illuminate\Validation\Rule;

final class AcademyQueryRequest extends AcademyRequest
{
    public function rules(): array
    {
        $available = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'min:3', 'max:100'],
            'status' => ['sometimes', 'string', 'max:64'],
            'academic_unit' => ['sometimes', 'integer', 'min:1'],
            'program' => ['sometimes', 'string', 'size:26'],
            'curriculum' => ['sometimes', 'integer', 'min:1'],
            'course_version' => ['sometimes', 'integer', 'min:1'],
            'cohort' => ['sometimes', 'string', 'size:26'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];

        [$fields, $sorts] = $this->endpointRules();
        $rules = array_intersect_key($available, array_flip(array_merge(['page', 'per_page'], $fields)));
        if ($sorts !== []) {
            $rules['sort'] = ['sometimes', Rule::in($sorts)];
            $rules['direction'] = ['sometimes', Rule::in(['asc', 'desc'])];
        }
        return $rules;
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function endpointRules(): array
    {
        $action = (string) ($this->route()?->getActionName() ?? '');
        $map = [
            'CatalogQueryController@academicUnits' => [['search'], ['code', 'name', 'status']],
            'CatalogQueryController@programs' => [['search', 'academic_unit', 'status'], ['code', 'name', 'status']],
            'CatalogQueryController@curricula' => [['program', 'status'], ['version', 'status']],
            'CatalogQueryController@curriculumCourses' => [['search', 'status'], ['sequence', 'code', 'name', 'status']],
            'CatalogQueryController@courses' => [['search', 'status'], ['code', 'name', 'status']],
            'CatalogQueryController@courseVersions' => [['status'], ['version', 'status']],
            'CatalogQueryController@courseVersionModules' => [[], ['sequence', 'name']],
            'CatalogQueryController@moduleLessons' => [[], ['sequence', 'name']],
            'CatalogQueryController@lessonResources' => [['status'], ['sequence', 'kind', 'status']],
            'CatalogQueryController@cohorts' => [['search', 'academic_unit', 'curriculum', 'status'], ['code', 'name', 'starts_at', 'status']],
            'ClassQueryController@index' => [['search', 'academic_unit', 'course_version', 'cohort', 'status'], ['code', 'name', 'status']],
            'ClassQueryController@roster' => [['search', 'status'], ['name', 'status', 'enrolled_at']],
            'ClassQueryController@people' => [['search'], ['name']],
            'ClassQueryController@instructorCandidates' => [['search'], ['name']],
            'ClassQueryController@sessions' => [['status', 'date_from', 'date_to'], ['starts_at', 'status']],
            'ClassQueryController@progress' => [[], []],
            'AssessmentQueryController@index' => [['search', 'status'], ['name', 'status']],
            'AssessmentQueryController@attempts' => [['status'], ['name', 'status', 'starts_at']],
            'DocumentQueryController@certificates' => [['status'], ['name', 'status', 'issued_at', 'version']],
            'DocumentQueryController@transcripts' => [['status'], ['status', 'issued_at', 'version']],
            'EligibleFileController@certificate' => [['search'], ['name']],
            'EligibleFileController@transcript' => [['search'], ['name']],
        ];
        foreach ($map as $suffix => $config) {
            if (str_ends_with($action, $suffix)) { return $config; }
        }

        // Used only by isolated request-validation probes; production actions are all mapped above.
        return [['search', 'status', 'academic_unit', 'program', 'curriculum', 'course_version', 'cohort', 'date_from', 'date_to'], ['code', 'name', 'status', 'version', 'starts_at', 'issued_at', 'enrolled_at']];
    }
}
