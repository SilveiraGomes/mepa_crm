const seg = (value: string) => encodeURIComponent(value)
const employment = (id: string) => `hr/employments/${seg(id)}`
const rule = (code: string, version: number) => `hr/payroll-rules/${seg(code)}/${version}`
const run = (id: string) => `hr/payroll/runs/${seg(id)}`

/** RH / payroll API paths (P0.10-F2A foundation + F2B runs). Targets are public ids; one path per explicit transition. */
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
  runs: () => 'hr/payroll/runs',
  run,
  runEmployees: (id: string) => `${run(id)}/employees`,
  runSummary: (id: string) => `${run(id)}/summary`,
  runSummaryCsv: (id: string) => `${run(id)}/summary.csv`,
  runCalculate: (id: string) => `${run(id)}/calculate`,
  runApprove: (id: string) => `${run(id)}/approve`,
  runPost: (id: string) => `${run(id)}/post`,
  runPay: (id: string) => `${run(id)}/pay`,
  runReverse: (id: string) => `${run(id)}/reverse`,
  runCancel: (id: string) => `${run(id)}/cancel`,
}
