import { lazy, Suspense, type ComponentType, type ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { AppProvider, useApp } from './context/AppContext'
import { AppShell } from './layout/AppShell'
import { LoadingState, PageHeader } from './components/ui'

const AuthPage = lazy(() => import('./pages/AuthPage').then((m) => ({ default: m.AuthPage })))
const DashboardPage = lazy(() => import('./pages/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const catalog = () => import('./pages/CatalogPages')
const content = () => import('./pages/ContentPages')
const classes = () => import('./pages/ClassPages')
const academic = () => import('./pages/AcademicPages')
const peoplePages = () => import('./pages/PeoplePages')
const personAreas = () => import('./pages/PersonAreaPages')
const householdPages = () => import('./pages/HouseholdPages')
const territorialPages = () => import('./pages/TerritorialPages')
const physicalPages = () => import('./pages/PhysicalPages')
const filesPages = () => import('./pages/FilesPages')
const documentsPages = () => import('./pages/DocumentsPages')
const patrimonyPages = () => import('./pages/PatrimonyPages')

function named<T extends ComponentType<Record<string, never>>>(load: () => Promise<Record<string, unknown>>, name: string) {
  return lazy(async () => ({ default: (await load())[name] as T }))
}

const CatalogListPage = lazy(() => catalog().then((m) => ({ default: m.CatalogListPage })))
const CatalogDetailPage = lazy(() => catalog().then((m) => ({ default: m.CatalogDetailPage })))
const CurriculumCoursesPage = named(content, 'CurriculumCoursesPage')
const CourseVersionsPage = named(content, 'CourseVersionsPage')
const CourseVersionPage = named(content, 'CourseVersionPage')
const ModulesPage = named(content, 'ModulesPage')
const LessonsPage = named(content, 'LessonsPage')
const ResourcesPage = named(content, 'ResourcesPage')
const ClassesPage = named(classes, 'ClassesPage')
const ClassOverviewPage = named(classes, 'ClassOverviewPage')
const EnrollmentsPage = named(classes, 'EnrollmentsPage')
const EnrollmentPage = named(classes, 'EnrollmentPage')
const InstructorsPage = named(classes, 'InstructorsPage')
const SessionsPage = named(classes, 'SessionsPage')
const SessionPage = named(classes, 'SessionPage')
const AttendancePage = named(academic, 'AttendancePage')
const AssessmentsPage = named(academic, 'AssessmentsPage')
const AssessmentPage = named(academic, 'AssessmentPage')
const AttemptsPage = named(academic, 'AttemptsPage')
const AttemptPage = named(academic, 'AttemptPage')
const ProgressPage = named(academic, 'ProgressPage')
const CertificatesPage = named(academic, 'CertificatesPage')
const CertificatePage = named(academic, 'CertificatePage')
const TranscriptLandingPage = named(academic, 'TranscriptLandingPage')
const TranscriptPage = named(academic, 'TranscriptPage')
const PeopleListPage = named(peoplePages, 'PeopleListPage')
const PersonCreatePage = named(peoplePages, 'PersonCreatePage')
const PersonDetailPage = named(peoplePages, 'PersonDetailPage')
const PersonEditPage = named(peoplePages, 'PersonEditPage')
const PeopleExportPage = named(peoplePages, 'PeopleExportPage')
const ContactsPage = named(personAreas, 'ContactsPage')
const AddressesPage = named(personAreas, 'AddressesPage')
const FamilyPage = named(personAreas, 'FamilyPage')
const RelationshipsPage = named(personAreas, 'RelationshipsPage')
const HouseholdsPage = named(householdPages, 'HouseholdsPage')
const HouseholdCreatePage = named(householdPages, 'HouseholdCreatePage')
const HouseholdDetailPage = named(householdPages, 'HouseholdDetailPage')
const TerritorialTreePage = named(territorialPages, 'TerritorialTreePage')
const TerritorialListPage = named(territorialPages, 'TerritorialListPage')
const TerritorialDetailPage = named(territorialPages, 'TerritorialDetailPage')
const TerritorialFormPage = named(territorialPages, 'TerritorialFormPage')
const FilesListPage = named(filesPages, 'FilesListPage')
const FileUploadPage = named(filesPages, 'FileUploadPage')
const FileDetailPage = named(filesPages, 'FileDetailPage')
const DocumentsListPage = named(documentsPages, 'DocumentsListPage')
const DocumentCreatePage = named(documentsPages, 'DocumentCreatePage')
const DocumentDetailPage = named(documentsPages, 'DocumentDetailPage')
const LocationsPage = named(physicalPages, 'LocationsPage')
const LocationCreatePage = named(physicalPages, 'LocationCreatePage')
const LocationDetailPage = named(physicalPages, 'LocationDetailPage')
const LocationEditPage = named(physicalPages, 'LocationEditPage')
const UnitLinksPage = named(physicalPages, 'UnitLinksPage')
const PropertiesPage = named(patrimonyPages, 'PropertiesPage')
const PropertyFormPage = named(patrimonyPages, 'PropertyFormPage')
const PropertyDetailPage = named(patrimonyPages, 'PropertyDetailPage')
const TemplesPage = named(patrimonyPages, 'TemplesPage')
const TempleFormPage = named(patrimonyPages, 'TempleFormPage')
const TempleDetailPage = named(patrimonyPages, 'TempleDetailPage')

function Protected({ children }: { children: ReactNode }) {
  const { session } = useApp()
  const location = useLocation()
  return session ? children : <Navigate to="/entrar" state={{ from: `${location.pathname}${location.search}` }} replace />
}

function NotFound() {
  return <><PageHeader title="Página não encontrada" /><div className="card"><p>O endereço indicado não existe.</p></div></>
}

function App() {
  return <AppProvider><BrowserRouter><Suspense fallback={<main className="main"><LoadingState /></main>}><Routes>
    <Route path="/entrar" element={<AuthPage />} />
    <Route element={<Protected><AppShell /></Protected>}>
      <Route path="/estrutura-territorial" element={<TerritorialTreePage />} />
      <Route path="/estrutura-territorial/lista" element={<TerritorialListPage />} />
      <Route path="/estrutura-territorial/nova" element={<TerritorialFormPage />} />
      <Route path="/estrutura-territorial/:id" element={<TerritorialDetailPage />} />
      <Route path="/estrutura-territorial/:id/editar" element={<TerritorialFormPage />} />
      <Route path="/ficheiros" element={<FilesListPage />} />
      <Route path="/ficheiros/carregar" element={<FileUploadPage />} />
      <Route path="/ficheiros/:id" element={<FileDetailPage />} />
      <Route path="/documentos" element={<DocumentsListPage />} />
      <Route path="/documentos/novo" element={<DocumentCreatePage />} />
      <Route path="/documentos/:id" element={<DocumentDetailPage />} />
      <Route path="/locais" element={<LocationsPage />} />
      <Route path="/locais/novo" element={<LocationCreatePage />} />
      <Route path="/locais/:id" element={<LocationDetailPage />} />
      <Route path="/locais/:id/editar" element={<LocationEditPage />} />
      <Route path="/imoveis" element={<PropertiesPage />} />
      <Route path="/imoveis/novo" element={<PropertyFormPage />} />
      <Route path="/imoveis/:id" element={<PropertyDetailPage />} />
      <Route path="/imoveis/:id/editar" element={<PropertyFormPage />} />
      <Route path="/templos" element={<TemplesPage />} />
      <Route path="/templos/novo" element={<TempleFormPage />} />
      <Route path="/templos/:id" element={<TempleDetailPage />} />
      <Route path="/templos/:id/editar" element={<TempleFormPage />} />
      <Route path="/ligacoes" element={<UnitLinksPage />} />
      <Route path="/pessoas" element={<PeopleListPage />} />
      <Route path="/pessoas/nova" element={<PersonCreatePage />} />
      <Route path="/pessoas/exportar" element={<PeopleExportPage />} />
      <Route path="/pessoas/:personId" element={<PersonDetailPage />} />
      <Route path="/pessoas/:personId/editar" element={<PersonEditPage />} />
      <Route path="/pessoas/:personId/contactos" element={<ContactsPage />} />
      <Route path="/pessoas/:personId/enderecos" element={<AddressesPage />} />
      <Route path="/pessoas/:personId/familia" element={<FamilyPage />} />
      <Route path="/pessoas/:personId/relacoes" element={<RelationshipsPage />} />
      <Route path="/familias" element={<HouseholdsPage />} />
      <Route path="/familias/nova" element={<HouseholdCreatePage />} />
      <Route path="/familias/:householdId" element={<HouseholdDetailPage />} />
      <Route path="/academia" element={<DashboardPage />} />
      <Route path="/academia/unidades" element={<CatalogListPage resource="units" />} />
      <Route path="/academia/unidades/:id" element={<CatalogDetailPage resource="units" />} />
      <Route path="/academia/programas" element={<CatalogListPage resource="programs" />} />
      <Route path="/academia/programas/:id" element={<CatalogDetailPage resource="programs" />} />
      <Route path="/academia/curriculos" element={<CatalogListPage resource="curricula" />} />
      <Route path="/academia/curriculos/:id" element={<CatalogDetailPage resource="curricula" />} />
      <Route path="/academia/curriculos/:id/cursos" element={<CurriculumCoursesPage />} />
      <Route path="/academia/cursos" element={<CatalogListPage resource="courses" />} />
      <Route path="/academia/cursos/:id" element={<CatalogDetailPage resource="courses" />} />
      <Route path="/academia/cursos/:id/versoes" element={<CourseVersionsPage />} />
      <Route path="/academia/versoes/:versionId" element={<CourseVersionPage />} />
      <Route path="/academia/versoes/:versionId/modulos" element={<ModulesPage />} />
      <Route path="/academia/versoes/:versionId/modulos/:moduleId/aulas" element={<LessonsPage />} />
      <Route path="/academia/versoes/:versionId/modulos/:moduleId/aulas/:lessonId/recursos" element={<ResourcesPage />} />
      <Route path="/academia/coortes" element={<CatalogListPage resource="cohorts" />} />
      <Route path="/academia/coortes/:id" element={<CatalogDetailPage resource="cohorts" />} />
      <Route path="/academia/turmas" element={<ClassesPage />} />
      <Route path="/academia/turmas/:classId" element={<ClassOverviewPage />} />
      <Route path="/academia/turmas/:classId/matriculas" element={<EnrollmentsPage />} />
      <Route path="/academia/turmas/:classId/matriculas/:enrollmentId" element={<EnrollmentPage />} />
      <Route path="/academia/turmas/:classId/instrutores" element={<InstructorsPage />} />
      <Route path="/academia/turmas/:classId/sessoes" element={<SessionsPage />} />
      <Route path="/academia/turmas/:classId/sessoes/:sessionId" element={<SessionPage />} />
      <Route path="/academia/sessoes/:sessionId/presencas" element={<AttendancePage />} />
      <Route path="/academia/turmas/:classId/avaliacoes" element={<AssessmentsPage />} />
      <Route path="/academia/turmas/:classId/avaliacoes/:assessmentId" element={<AssessmentPage />} />
      <Route path="/academia/turmas/:classId/avaliacoes/:assessmentId/tentativas" element={<AttemptsPage />} />
      <Route path="/academia/turmas/:classId/tentativas/:attemptId" element={<AttemptPage />} />
      <Route path="/academia/matriculas/:enrollmentId/progresso" element={<ProgressPage />} />
      <Route path="/academia/turmas/:classId/certificados" element={<CertificatesPage />} />
      <Route path="/academia/turmas/:classId/certificados/:certificateId" element={<CertificatePage />} />
      <Route path="/academia/historicos" element={<TranscriptLandingPage />} />
      <Route path="/academia/curriculos/:curriculumId/pessoas/:personId/historicos/:transcriptId" element={<TranscriptPage />} />
      <Route path="*" element={<NotFound />} />
    </Route>
    <Route path="/" element={<Navigate to="/academia" replace />} />
  </Routes></Suspense></BrowserRouter></AppProvider>
}

export default App
