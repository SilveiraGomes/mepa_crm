const seg = (value: string) => encodeURIComponent(value)
const employment = (id: string) => `hr/employments/${seg(id)}`
const rule = (code: string, version: number) => `hr/payroll-rules/${seg(code)}/${version}`

/** RH / payroll API paths (P0.10-F2A). Targets are public ids. There is no run / approve / post / pay path before F2B. */
export const hr = {
  context: () => 'hr/context',
  status: () => 'hr/payroll/status',
  readiness: () => 'hr/payroll/readiness',
  employments: () => 'hr/employments',
  employment,
  endEmployment: (id: string) => `${employment(id)}/end`,
  compensation: (id: string) => `${employment(id)}/compensation`,
  stopCompensation: (id: string) => `${employment(id)}/compensation/stop`,
  compensations: () => 'hr/compensations',
  components: () => 'hr/components',
  rules: () => 'hr/payroll-rules',
  rule,
  approveRule: (code: string, version: number) => `${rule(code, version)}/approve`,
  retireRule: (code: string, version: number) => `${rule(code, version)}/retire`,
}
