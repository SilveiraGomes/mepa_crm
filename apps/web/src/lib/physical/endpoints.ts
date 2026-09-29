const seg = (value: string) => encodeURIComponent(value)
const location = (id: string) => `physical/locations/${seg(id)}`
const link = (id: string, ref: string) => `${location(id)}/links/${seg(ref)}`
const property = (id: string) => `physical/properties/${seg(id)}`
const temple = (id: string) => `physical/temples/${seg(id)}`

/** Physical API paths. Targets are public ids; links are addressed by an opaque ref under their location. */
export const ph = {
  context: () => 'physical/context',
  locations: () => 'physical/locations',
  location,
  address: (id: string) => `${location(id)}/address`,
  activate: (id: string) => `${location(id)}/activate`,
  close: (id: string) => `${location(id)}/close`,
  publish: (id: string) => `${location(id)}/publish`,
  unpublish: (id: string) => `${location(id)}/unpublish`,
  links: (id: string) => `${location(id)}/links`,
  endLink: (id: string, ref: string) => `${link(id, ref)}/end`,
  transfer: (id: string, ref: string) => `${link(id, ref)}/transfer`,
  setPrimary: (id: string, ref: string) => `${link(id, ref)}/set-primary`,
  unitLinks: () => 'physical/links',
  properties: () => 'physical/properties',
  property,
  ownership: (id: string) => `${property(id)}/ownership-status`,
  propertyActivate: (id: string) => `${property(id)}/activate`,
  propertyClose: (id: string) => `${property(id)}/close`,
  externalOwner: (id: string) => `${property(id)}/external-owner`,
  temples: () => 'physical/temples',
  temple,
  templeActivate: (id: string) => `${temple(id)}/activate`,
  templeClose: (id: string) => `${temple(id)}/close`,
}
