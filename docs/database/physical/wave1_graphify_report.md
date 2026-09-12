# Graphify ? Wave 1

Consulta pr?via antes do c?digo: people, person_relationships, organizational_units, physical_locations, migrations e foreign keys. Grafo inicial: 1183 n?s e 1797 arestas. Schema derivado do cat?logo aprovado.

Actualiza??o incremental com AST local e extrac??o sem?ntica delegada conforme a skill Graphify. Documentos anteriores e os 199 n?s de tabelas foram preservados. Cada FK desta onda tem um n? pr?prio para distinguir subject_person_id e related_person_id. Migrations up/down ligam-se ?s tabelas can?nicas; W1-F01 permanece explicitamente pendente.

Contagens finais e diagn?stico encontram-se em [wave1_graphify_validation.json](wave1_graphify_validation.json). O shrink guard nativo passou sem force; nenhum endpoint ausente, pendente ou self-loop. Cobertura de 31 migrations e 51 FKs planeadas (50 executadas), com 201 n?s e 526 rela??es do dicion?rio anterior preservados.

[Seis consultas](wave1_graphify_queries.json), todas exit 0, cobrem pessoa, fam?lia, ?rvore, localiza??o, ficheiro de migration e FK departamental. S?o traversais por or?amento; enforcement ? comprovado pelos testes MySQL. HTML actualizado em graphify-out/graph.html, artefacto local ignorado pelo Git; os resumos audit?veis ficam neste direct?rio.

Tokens do host: N?O INSTRUMENTADOS. Os zeros embutidos na extrac??o s?o placeholders, n?o medi??es de agentes. Backend LLM externo: zero chamadas; nenhuma chave API solicitada. D-02?D-12 preservadas.
