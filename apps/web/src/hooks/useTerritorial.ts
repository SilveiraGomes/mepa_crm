import { useCallback,useEffect,useState } from 'react'
import { territorialGet } from '../lib/territorial/client'
import { toPeopleError } from '../lib/people/errors'
import { isAbort,type UiError } from '../lib/academy/errors'
import type { Item,Paginated } from '../types/academy'

export function useTerritorialItem<T>(path:string|null){const[state,setState]=useState<{data:T|null;loading:boolean;error:UiError|null}>({data:null,loading:Boolean(path),error:null});const[nonce,setNonce]=useState(0);useEffect(()=>{if(!path){setState({data:null,loading:false,error:null});return}const c=new AbortController();setState(v=>({...v,loading:true,error:null}));territorialGet<Item<T>>(path,{},c.signal).then(v=>setState({data:v.data,loading:false,error:null}),e=>{if(!isAbort(e))setState({data:null,loading:false,error:toPeopleError(e)})});return()=>c.abort()},[path,nonce]);return{...state,reload:useCallback(()=>setNonce(v=>v+1),[])}}
export function useTerritorialPage<T>(path:string,query:Record<string,string|number>={}){const[state,setState]=useState<{data:Paginated<T>|null;loading:boolean;error:UiError|null}>({data:null,loading:true,error:null});const[nonce,setNonce]=useState(0);const key=JSON.stringify(query);useEffect(()=>{const c=new AbortController();setState(v=>({...v,loading:true,error:null}));territorialGet<Paginated<T>>(path,JSON.parse(key),c.signal).then(v=>setState({data:v,loading:false,error:null}),e=>{if(!isAbort(e))setState({data:null,loading:false,error:toPeopleError(e)})});return()=>c.abort()},[path,key,nonce]);return{...state,reload:()=>setNonce(v=>v+1)}}
