const seg=(value:string)=>encodeURIComponent(value)
const unit=(id:string)=>`territorial/units/${seg(id)}`
export const te={context:()=> 'territorial/context',units:()=> 'territorial/units',roots:()=> 'territorial/roots',unit,children:(id:string)=>`${unit(id)}/children`,path:(id:string)=>`${unit(id)}/path`,move:(id:string)=>`${unit(id)}/move`,lifecycle:(id:string)=>`${unit(id)}/lifecycle`}
