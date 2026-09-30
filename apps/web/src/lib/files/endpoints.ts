const seg = (value: string) => encodeURIComponent(value)
const file = (id: string) => `files/${seg(id)}`
const document = (id: string) => `documents/${seg(id)}`

/** Documents/Files API paths. Targets are public ids; content only through the authorized /content endpoints. */
export const fl = {
  context: () => 'files/context',
  files: () => 'files',
  file,
  content: (id: string) => `${file(id)}/content`,
  tombstone: (id: string) => `${file(id)}/tombstone`,
  restore: (id: string) => `${file(id)}/restore`,
  classification: (id: string) => `${file(id)}/classification`,
  owner: (id: string) => `${file(id)}/owner`,
  documents: () => 'documents',
  document,
  versions: (id: string) => `${document(id)}/versions`,
  versionContent: (id: string, version: string) => `${document(id)}/versions/${seg(version)}/content`,
  archive: (id: string) => `${document(id)}/archive`,
  documentRestore: (id: string) => `${document(id)}/restore`,
  documentOwner: (id: string) => `${document(id)}/owner`,
}
