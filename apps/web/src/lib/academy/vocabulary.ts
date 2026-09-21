// D-11 (exact states and transitions) is open and config/academy.php ships empty. No endpoint
// exposes which target states or attendance statuses are approved (A4_API_CONTRACT_GAP-03), and
// the UI must not hardcode institutional states. Every vocabulary is therefore "unavailable"
// until the API offers one; screens that need it show an explanatory state instead of a
// free-text or invented list. Tests feed a vocabulary through VocabularyContext.

export type VocabularyKey =
  | 'enrollment.transitions'
  | 'session.transitions'
  | 'attempt.transitions'
  | 'attendance.statuses'

export interface VocabularyOption {
  value: string
  label: string
  from?: string
}

export type Vocabulary = Partial<Record<VocabularyKey, readonly VocabularyOption[]>>

export const NO_VOCABULARY: Vocabulary = {}

export function optionsFor(vocabulary: Vocabulary, key: VocabularyKey): readonly VocabularyOption[] | null {
  const options = vocabulary[key]
  return options && options.length > 0 ? options : null
}

export function targetsFor(vocabulary: Vocabulary, key: VocabularyKey, from: string): readonly VocabularyOption[] | null {
  const options = optionsFor(vocabulary, key)?.filter((option) => option.from === undefined || option.from === from)
  return options && options.length > 0 ? options : null
}

export const VOCABULARY_PENDING_MESSAGE =
  'Os estados permitidos ainda não estão definidos (política D-11 pendente), por isso esta acção não está disponível.'
