<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Configuration\SystemConfiguration;
use App\Entity\Customer;
use App\Entity\MetaTableTypeInterface;
use App\Entity\Project;
use App\Entity\ProjectComment;
use App\Entity\ProjectRate;
use App\Entity\Team;
use App\Event\ProjectDetailControllerEvent;
use App\Event\ProjectMetaDefinitionEvent;
use App\Event\ProjectMetaDisplayEvent;
use App\Export\Spreadsheet\EntityWithMetaFieldsExporter;
use App\Export\Spreadsheet\Writer\BinaryFileResponseWriter;
use App\Export\Spreadsheet\Writer\XlsxWriter;
use App\Form\ProjectCommentForm;
use App\Form\ProjectEditForm;
use App\Form\ProjectRateForm;
use App\Form\ProjectTeamPermissionForm;
use App\Form\Toolbar\ProjectToolbarForm;
use App\Form\Type\ProjectType;
use App\Project\ProjectDuplicationService;
use App\Project\ProjectService;
use App\Project\ProjectStatisticService;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRateRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\ActivityQuery;
use App\Repository\Query\ProjectQuery;
use App\Repository\TeamRepository;
use App\Utils\Context;
use App\Utils\DataTable;
use App\Utils\PageSetup;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller used to manage projects.
 */
#[Route(path: '/admin/project')]
final class ProjectController extends AbstractController
{

    private $logger;
    private $client;
    private $projectDirectory;
    private $activityRepository;


    private string $apiToken;

    // public function __construct(HttpClientInterface $client, string $apiUrl, string $apiToken)
    // {
    //     $this->client = $client;
    //     $this->apiUrl = $apiUrl;
    //     $this->apiToken = $apiToken;
    // }

    public function __construct(ActivityRepository $activityRepository, string $projectDirectory, private ProjectRepository $repository, private SystemConfiguration $configuration, private EventDispatcherInterface $dispatcher, private ProjectService $projectService, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->client = HttpClient::create();

        $this->apiToken = $this->getToken();

        $this->projectDirectory = $projectDirectory;
        $this->activityRepository = $activityRepository;
    }

    #[Route(path: '/', defaults: ['page' => 1], name: 'admin_project', methods: ['GET'])]
    #[Route(path: '/page/{page}', requirements: ['page' => '[1-9]\d*'], name: 'admin_project_paginated', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('listing', 'project')"))]
    public function indexAction(int $page, Request $request): Response
    {
        $query = new ProjectQuery();
        $query->setCurrentUser($this->getUser());
        $query->setPage($page);

        $form = $this->getToolbarForm($query);
        if ($this->handleSearch($form, $request)) {
            return $this->redirectToRoute('admin_project');
        }

        $entries = $this->repository->getPagerfantaForQuery($query);
        $metaColumns = $this->findMetaColumns($query);


        // Fetch activities for each project
        $activitiesByProject = [];
        foreach ($entries as $project) {
            $activities = $this->activityRepository->findBy(['project' => $project->getId()]);
            $activitiesByProject[$project->getId()] = $activities;
        }


        $table = new DataTable('project_admin', $query);
        $table->setPagination($entries);
        $table->setSearchForm($form);
        $table->setPaginationRoute('admin_project_paginated');
        $table->setReloadEvents('kimai.projectUpdate kimai.projectDelete kimai.projectTeamUpdate');

        $table->addColumn('name', ['class' => 'alwaysVisible']);
        $table->addColumn('customer', ['class' => 'd-none']);
        $table->addColumn('comment', ['class' => 'd-none', 'title' => 'description']);
        $table->addColumn('orderNumber', ['class' => 'd-none']);
        $table->addColumn('orderDate', ['class' => 'd-none']);
        $table->addColumn('project_start', ['class' => 'd-none']);
        $table->addColumn('project_end', ['class' => 'd-none']);
        $table->addColumn('Project_Id', ['class' => 'd-none']);

        $table->addColumn('activities', ['class' => 'd-none', 'title' => 'activities']);

        foreach ($metaColumns as $metaColumn) {
            $table->addColumn('mf_' . $metaColumn->getName(), ['title' => $metaColumn->getLabel(), 'class' => 'd-none', 'orderBy' => false, 'data' => $metaColumn]);
        }

        if ($this->isGranted('budget_money', 'project')) {
            $table->addColumn('budget', ['class' => 'd-none text-end w-min', 'title' => 'budget']);
        }

        if ($this->isGranted('budget_time', 'project')) {
            $table->addColumn('timeBudget', ['class' => 'd-none text-end w-min', 'title' => 'timeBudget']);
        }

        $table->addColumn('billable', ['class' => 'd-none text-center w-min', 'orderBy' => false]);
        $table->addColumn('team', ['class' => 'text-center w-min', 'orderBy' => false]);
        $table->addColumn('visible', ['class' => 'd-none text-center w-min']);
        $table->addColumn('actions', ['class' => 'actions']);

        $page = $this->createPageSetup();
        $page->setDataTable($table);
        $page->setActionName('projects');

        return $this->render('project/index.html.twig', [
            'page_setup' => $page,
            'dataTable' => $table,
            'metaColumns' => $metaColumns,
            'projects' => $entries,
            'activitiesByProject' => $activitiesByProject,
            'now' => $this->getDateTimeFactory()->createDateTime(),
        ]);
    }

    /**
     * @param ProjectQuery $query
     * @return MetaTableTypeInterface[]
     */
    private function findMetaColumns(ProjectQuery $query): array
    {
        $event = new ProjectMetaDisplayEvent($query, ProjectMetaDisplayEvent::PROJECT);
        $this->dispatcher->dispatch($event);

        return $event->getFields();
    }

    #[Route(path: '/{id}/permissions', name: 'admin_project_permissions', methods: ['GET', 'POST'])]
    #[IsGranted('permissions', 'project')]
    public function teamPermissions(Project $project, Request $request): Response
    {
        $form = $this->createForm(ProjectTeamPermissionForm::class, $project, [
            'action' => $this->generateUrl('admin_project_permissions', ['id' => $project->getId()]),
            'method' => 'POST',
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->projectService->updateProject($project);
                $this->flashSuccess('action.update.success');

                if ($this->isGranted('view', $project)) {
                    return $this->redirectToRoute('project_details', ['id' => $project->getId()]);
                }

                return $this->redirectToRoute('admin_project');
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->render('project/permissions.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'project' => $project,
            'form' => $form->createView()
        ]);
    }

    #[Route(path: '/create/{customer}', name: 'admin_project_create_with_customer', methods: ['GET', 'POST'])]
    #[IsGranted('create_project')]
    public function createWithCustomerAction(Request $request, Customer $customer): Response
    {
        return $this->createProject($request, $customer);
    }

    #[Route(path: '/create', name: 'admin_project_create', methods: ['GET', 'POST'])]
    #[IsGranted('create_project')]
    public function createAction(Request $request): Response
    {
        return $this->createProject($request, null);
    }

    public function copyFolderContents(string $sourceFolderPath, string $destinationFolderPath): bool
    {
        $baseUrl = rtrim($_ENV['NEXTCLOUD_BASE_URL'], '/');
        $username = $_ENV['NEXTCLOUD_USERNAME'];
        $password = $_ENV['NEXTCLOUD_PASSWORD'];
        $domain = $_ENV['NEXTCLOUD_DOMAIN'];

        $sourceUrl = $baseUrl . '/' . ltrim($sourceFolderPath, '/');
        $destinationUrl = $baseUrl . '/' . ltrim($destinationFolderPath, '/');

        $this->logger->info("Source URL: $sourceUrl");
        $this->logger->info("Destination URL: $destinationUrl");

        try {
            // List all contents of the source folder
            $response = $this->client->request('PROPFIND', $sourceUrl, [
                'auth_basic' => [$username, $password],
                'headers' => [
                    'Depth' => '1',
                    'Content-Type' => 'application/xml',
                ],
                'body' => '<?xml version="1.0" encoding="UTF-8"?>
                <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
                  <d:prop>
                    <d:displayname/>
                  </d:prop>
                </d:propfind>',
            ]);

            if ($response->getStatusCode() !== 207) {
                $this->logger->error("Failed to list source folder contents. Status code: " . $response->getStatusCode());
                return false;
            }

            $body = $response->getContent();
            $xml = new \SimpleXMLElement($body);
            $xml->registerXPathNamespace('d', 'DAV:');

            foreach ($xml->xpath('//d:response') as $response) {
                $href = (string) $response->xpath('d:href')[0];
                $href = rtrim($href, '/'); // Remove trailing slashes

                // Extract item name
                $itemName = basename($href);
                if ($itemName === '' || $itemName === 'S000xx') { // Skip specific folder
                    continue;
                }

                // Resolve correct URLs
                $sourceItemUrl = $domain . $href;
                $targetPath = $destinationUrl . '/' . $itemName;


                // Copy file or folder
                if (!$this->copyItem($sourceItemUrl, $targetPath, $username, $password)) {
                    $this->logger->error("Failed to copy item: $sourceItemUrl to $targetPath");
                    return false;
                }
            }

            $this->logger->info("Successfully copied contents from $sourceFolderPath to $destinationFolderPath");
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error copying folder contents: ' . $e->getMessage());
            return false;
        }
    }


    private function copyItem(string $sourceUrl, string $destinationUrl, string $username, string $password): bool
    {
        try {
            $this->logger->info("Requesting copy from $sourceUrl to $destinationUrl");

            $response = $this->client->request('COPY', $sourceUrl, [
                'auth_basic' => [$username, $password],
                'headers' => [
                    'Destination' => $destinationUrl,
                    'Overwrite' => 'F',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->info("Copy response status code: $statusCode");

            return $statusCode === 201 || $statusCode === 204;
        } catch (\Exception $e) {
            $this->logger->error('Error copying item: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetches the API token using username and password.
     */
    private function getToken(): string
    {
        $apiUrl = rtrim($_ENV['SEAFILEAPIURL'], '/') . '/api2/auth-token/';

        try {
            $response = $this->client->request('POST', $apiUrl, [
                'json' => [
                    'username' => $_ENV['SEAFILE_USERNAME'],
                    'password' => $_ENV['SEAFILE_PASSWORD'],
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $data = $response->toArray();
                $this->logger->info('Token fetched successfully.');
                return $data['token'];
            } else {
                $this->logger->error("Failed to fetch token. Status code: $statusCode");
                throw new \Exception('Failed to fetch API token.');
            }
        } catch (\Exception $e) {
            $this->logger->error('Error fetching token: ' . $e->getMessage());
            throw $e;
        }
    }


/**
 * Creates a folder in Seafile if it does not already exist.
 */
public function createFolderIfNotExists(string $folderName): bool
{
    $apiUrl = rtrim($_ENV['SEAFILEAPIURL'], '/');
    $repoId = '245cd1b3-01cc-40a8-94b8-9cb36daed4f7'; // Replace with your actual repo ID
    $url = "$apiUrl/api2/repos/$repoId/dir/";

    try {
        // Adjust folder name for Seafile API requirements
        $folderName = ltrim($folderName, '/'); // Ensure no leading slash

        $response = $this->client->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Token ' . $this->apiToken, // Correct token format
            ],
            'query' => [
                'p' => "/$folderName", // Seafile requires the path to start with a slash
            ],
            'body' => [
                'operation' => 'mkdir', // Seafile API operation
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $responseContent = $response->getContent(false);

        if ($statusCode === 201) {
            $this->logger->info("Folder created successfully: $folderName");
            return true;
        } elseif ($statusCode === 405) {
            $this->logger->info("Folder already exists: $folderName");
            return true;
        } else {
            $this->logger->error("Unexpected status code: $statusCode for $folderName. Response: $responseContent");
            return false;
        }
    } catch (\Exception $e) {
        $this->logger->error('Error creating folder: ' . $e->getMessage());
        return false;
    }
}


    private function renameFilesInFolder(string $folderPath): bool
    {
        $baseUrl = rtrim($_ENV['NEXTCLOUD_BASE_URL'], '/');
        $username = $_ENV['NEXTCLOUD_USERNAME'];
        $password = $_ENV['NEXTCLOUD_PASSWORD'];
        $folderUrl = $baseUrl . '/' . ltrim($folderPath, '/');
        $domain = $_ENV['NEXTCLOUD_DOMAIN'];

        try {
            // List all contents of the folder including nested folders
            $response = $this->client->request('PROPFIND', $folderUrl, [
                'auth_basic' => [$username, $password],
                'headers' => [
                    'Depth' => 'infinity',
                    'Content-Type' => 'application/xml',
                ],
                'body' => '<?xml version="1.0" encoding="UTF-8"?>
                <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
                  <d:prop>
                    <d:displayname/>
                  </d:prop>
                </d:propfind>',
            ]);

            if ($response->getStatusCode() !== 207) {
                $this->logger->error("Failed to list folder contents. Status code: " . $response->getStatusCode());
                return false;
            }

            $body = $response->getContent();
            $xml = new \SimpleXMLElement($body);
            $xml->registerXPathNamespace('d', 'DAV:');

            foreach ($xml->xpath('//d:response') as $response) {
                $href = (string) $response->xpath('d:href')[0];
                $href = rtrim($href, '/'); // Remove trailing slashes

                // Extract item name and folder path
                $itemName = basename($href);
                $itemPath = ltrim(substr($href, strlen($baseUrl)), '/');

                if ($itemName === '' || $itemName === 'S000xx') { // Skip specific folder
                    continue;
                }

                // Rename files based on their name patterns
                if (pathinfo($itemName, PATHINFO_EXTENSION)) {
                    $newItemName = $this->generateNewFileName($itemName, $folderPath);
                    if ($newItemName) {
                        $sourceItemUrl = $domain . $href;


                        $parsedUrl = parse_url($sourceItemUrl);
                        $path = $parsedUrl['path'] ?? '';

                        // Get the directory name (path without the file name)
                        $basePath = dirname($path);

                        // Reconstruct the URL with the base path
                        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $basePath;



                        $newItemUrl = $baseUrl . '/' . $newItemName;


                        if (!$this->renameItem($sourceItemUrl, $newItemUrl, $username, $password)) {
                            $this->logger->error("Failed to rename item: $sourceItemUrl to $newItemUrl");
                            return false;
                        }
                    }
                }
            }

            $this->logger->info("Successfully renamed files in folder $folderPath");
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error renaming files in folder: ' . $e->getMessage());
            return false;
        }
    }


    private function renameItem(string $sourceUrl, string $destinationUrl, string $username, string $password): bool
    {
        try {
            $this->logger->info("Requesting rename from $sourceUrl to $destinationUrl");

            $response = $this->client->request('MOVE', $sourceUrl, [
                'auth_basic' => [$username, $password],
                'headers' => [
                    'Destination' => $destinationUrl,
                    'Overwrite' => 'F',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->info("Rename response status code: $statusCode");

            if ($statusCode === 403) {
                $this->logger->error("Access denied while renaming item from $sourceUrl to $destinationUrl. Check permissions and paths.");
            }

            return $statusCode === 201 || $statusCode === 204;
        } catch (\Exception $e) {
            $this->logger->error('Error renaming item: ' . $e->getMessage());
            return false;
        }
    }

    private function generateNewFileName(string $itemName, string $folderPath): ?string
    {
        $folderId = basename($folderPath);
        $fileExtension = pathinfo($itemName, PATHINFO_EXTENSION);
        $fileBaseName = pathinfo($itemName, PATHINFO_FILENAME);

        if (strpos($fileBaseName, 'Auftrag_') === 0) {
            // Return with extension if it exists, otherwise just the base name
            return $fileExtension ? 'Auftrag_' . $folderId . '.' . $fileExtension : 'Auftrag_' . $folderId;
        }

        if (strpos($fileBaseName, 'Verfahrensanweisung_') === 0) {
            // Return with extension if it exists, otherwise just the base name
            return $fileExtension ? 'Verfahrensanweisung_' . $folderId . '.' . $fileExtension : 'Verfahrensanweisung_' . $folderId;
        }

        if (strpos($fileBaseName, '_Pruefanweisung') !== false) {
            // Return with the new folder ID and no extension if none exists
            return $fileExtension ? $folderId . '_Pruefanweisung.' . $fileExtension : $folderId . '_Pruefanweisung';
        }

        // Return null if no specific pattern matches
        return null;
    }


    private function createProject(Request $request, ?Customer $customer = null): Response
    {
        $project = $this->projectService->createNewProject($customer);

        $editForm = $this->createEditForm($project);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            try {
                $this->projectService->saveNewProject($project, new Context($this->getUser()));
                // Create the folder in Nextcloud
                $this->logger->info($project->getId());
                $folderPath =  $project->getId();
                $folderCreated = $this->createFolderIfNotExists($folderPath);

                if ($folderCreated) {
                    $this->flashSuccess('action.update.success');
                    // $sourceFolder = '/default';
                    // $copySuccess = $this->copyFolderContents($sourceFolder, $folderPath);
                    // $copySuccess = true;
                    // if ($copySuccess) {
                    //     $renameSuccess = $this->renameFilesInFolder($folderPath);
                    //     if ($renameSuccess) {
                    //         $this->flashSuccess('action.update.success');
                    //     } else {
                    //         $this->addFlash('error', 'Project Saved. Failed to rename contents in Nextcloud');
                    //     }
                    // } else {
                    //     $this->addFlash('error', 'Project Saved. Failed to copy contents in Nextcloud');
                    // }
                } else {
                    $this->addFlash('error', 'Project Saved. Failed to create folder in Cloud');
                }

                return $this->redirectToRouteAfterCreate('project_details', ['id' => $project->getId()]);
            } catch (\Exception $ex) {
                $this->handleFormUpdateException($ex, $editForm);
            }
        }

        return $this->render('project/edit.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'project' => $project,
            'form' => $editForm->createView()
        ]);
    }

    #[Route(path: '/{id}/comment_delete/{token}', name: 'project_comment_delete', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('edit', subject.getProject()) and is_granted('comments', subject.getProject())"), 'comment')]
    public function deleteCommentAction(ProjectComment $comment, string $token, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $projectId = $comment->getProject()->getId();

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('project.delete_comment', $token))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('project_details', ['id' => $projectId]);
        }

        $csrfTokenManager->refreshToken('project.delete_comment');

        try {
            $this->repository->deleteComment($comment);
        } catch (\Exception $ex) {
            $this->flashDeleteException($ex);
        }

        return $this->redirectToRoute('project_details', ['id' => $projectId]);
    }

    #[Route(path: '/{id}/comment_add', name: 'project_comment_add', methods: ['POST'])]
    #[IsGranted('comments', 'project')]
    public function addCommentAction(Project $project, Request $request): Response
    {
        $comment = new ProjectComment($project);
        $form = $this->getCommentForm($comment);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->repository->saveComment($comment);
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->redirectToRoute('project_details', ['id' => $project->getId()]);
    }

    #[Route(path: '/{id}/comment_pin/{token}', name: 'project_comment_pin', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('edit', subject.getProject()) and is_granted('comments', subject.getProject())"), 'comment')]
    public function pinCommentAction(ProjectComment $comment, string $token, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $projectId = $comment->getProject()->getId();

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('project.pin_comment', $token))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('project_details', ['id' => $projectId]);
        }

        $csrfTokenManager->refreshToken('project.pin_comment');

        $comment->setPinned(!$comment->isPinned());
        try {
            $this->repository->saveComment($comment);
        } catch (\Exception $ex) {
            $this->flashUpdateException($ex);
        }

        return $this->redirectToRoute('project_details', ['id' => $projectId]);
    }

    #[Route(path: '/{id}/create_team', name: 'project_team_create', methods: ['GET'])]
    #[IsGranted('create_team')]
    #[IsGranted('permissions', 'project')]
    public function createDefaultTeamAction(Project $project, TeamRepository $teamRepository): Response
    {
        $defaultTeam = $teamRepository->findOneBy(['name' => $project->getName()]);

        if (null === $defaultTeam) {
            $defaultTeam = new Team($project->getName());
        }

        $defaultTeam->addTeamlead($this->getUser());
        $defaultTeam->addProject($project);

        try {
            $teamRepository->saveTeam($defaultTeam);
        } catch (\Exception $ex) {
            $this->flashUpdateException($ex);
        }

        return $this->redirectToRoute('project_details', ['id' => $project->getId()]);
    }

    #[Route(path: '/{id}/activities/{page}', defaults: ['page' => 1], name: 'project_activities', methods: ['GET', 'POST'])]
    #[IsGranted('view', 'project')]
    public function activitiesAction(Project $project, int $page, ActivityRepository $activityRepository): Response
    {
        $query = new ActivityQuery();
        $query->setCurrentUser($this->getUser());
        $query->setPage($page);
        $query->setPageSize(5);
        $query->addProject($project);
        $query->setExcludeGlobals(true);
        $query->setShowBoth();
        $query->addOrderGroup('visible', ActivityQuery::ORDER_DESC);
        $query->addOrderGroup('name', ActivityQuery::ORDER_ASC);

        $entries = $activityRepository->getPagerfantaForQuery($query);

        return $this->render('project/embed_activities.html.twig', [
            'project' => $project,
            'activities' => $entries,
            'page' => $page,
            'now' => $this->getDateTimeFactory()->createDateTime(),
        ]);
    }

    #[Route(path: '/{id}/details', name: 'project_details', methods: ['GET', 'POST'])]
    #[IsGranted('view', 'project')]
    public function detailsAction(Project $project, TeamRepository $teamRepository, ProjectRateRepository $rateRepository, ProjectStatisticService $statisticService, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $event = new ProjectMetaDefinitionEvent($project);
        $this->dispatcher->dispatch($event);

        $stats = null;
        $defaultTeam = null;
        $commentForm = null;
        $attachments = [];
        $comments = null;
        $teams = null;
        $rates = [];
        $now = $this->getDateTimeFactory()->createDateTime();

        if ($this->isGranted('edit', $project)) {
            if ($this->isGranted('create_team')) {
                $defaultTeam = $teamRepository->findOneBy(['name' => $project->getName()]);
            }
            $rates = $rateRepository->getRatesForProject($project);
        }

        if ($this->isGranted('budget', $project) || $this->isGranted('time', $project)) {
            $stats = $statisticService->getBudgetStatisticModel($project, $now);
        }

        if ($this->isGranted('comments', $project)) {
            $comments = $this->repository->getComments($project);
            $commentForm = $this->getCommentForm(new ProjectComment($project))->createView();
        }

        if ($this->isGranted('permissions', $project) || $this->isGranted('details', $project) || $this->isGranted('view_team')) {
            $teams = $project->getTeams();
        }

        // additional boxes by plugins
        $event = new ProjectDetailControllerEvent($project);
        $this->dispatcher->dispatch($event);
        $boxes = $event->getController();

        $page = $this->createPageSetup();
        $page->setActionName('project');
        $page->setActionView('project_details');
        $page->setActionPayload(['project' => $project, 'token' => $csrfTokenManager->getToken('project.duplicate')]);

        return $this->render('project/details.html.twig', [
            'page_setup' => $page,
            'project' => $project,
            'comments' => $comments,
            'commentForm' => $commentForm,
            'attachments' => $attachments,
            'stats' => $stats,
            'team' => $defaultTeam,
            'teams' => $teams,
            'rates' => $rates,
            'now' => $now,
            'boxes' => $boxes
        ]);
    }

    #[Route(path: '/{id}/rate/{rate}', name: 'admin_project_rate_edit', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'project')]
    public function editRateAction(Project $project, ProjectRate $rate, Request $request, ProjectRateRepository $repository): Response
    {
        return $this->rateFormAction($project, $rate, $request, $repository, $this->generateUrl('admin_project_rate_edit', ['id' => $project->getId(), 'rate' => $rate->getId()]));
    }

    #[Route(path: '/{id}/rate', name: 'admin_project_rate_add', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'project')]
    public function addRateAction(Project $project, Request $request, ProjectRateRepository $repository): Response
    {
        $rate = new ProjectRate();
        $rate->setProject($project);

        return $this->rateFormAction($project, $rate, $request, $repository, $this->generateUrl('admin_project_rate_add', ['id' => $project->getId()]));
    }

    private function rateFormAction(Project $project, ProjectRate $rate, Request $request, ProjectRateRepository $repository, string $formUrl): Response
    {
        $form = $this->createForm(ProjectRateForm::class, $rate, [
            'action' => $formUrl,
            'method' => 'POST',
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $repository->saveRate($rate);
                $this->flashSuccess('action.update.success');

                return $this->redirectToRoute('project_details', ['id' => $project->getId()]);
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->render('project/rates.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'project' => $project,
            'form' => $form->createView()
        ]);
    }

    #[Route(path: '/{id}/edit', name: 'admin_project_edit', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'project')]
    public function editAction(Project $project, Request $request): Response
    {
        $editForm = $this->createEditForm($project);
        $editForm->handleRequest($request);

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            try {
                $this->projectService->updateProject($project);
                $this->flashSuccess('action.update.success');

                if ($this->isGranted('view', $project)) {
                    return $this->redirectToRoute('project_details', ['id' => $project->getId()]);
                } else {
                    return new Response();
                }
            } catch (\Exception $ex) {
                $this->flashUpdateException($ex);
            }
        }

        return $this->render('project/edit.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'project' => $project,
            'form' => $editForm->createView()
        ]);
    }

    #[Route(path: '/{id}/duplicate/{token}', name: 'admin_project_duplicate', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'project')]
    public function duplicateAction(Project $project, string $token, ProjectDuplicationService $projectDuplicationService, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('project.duplicate', $token))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToRoute('project_details', ['id' => $project->getId()]);
        }

        $csrfTokenManager->refreshToken('project.duplicate');

        $newProject = $projectDuplicationService->duplicate($project, $project->getName() . ' [COPY]');

        $this->flashSuccess('action.update.success');

        return $this->redirectToRoute('project_details', ['id' => $newProject->getId()]);
    }

    #[Route(path: '/{id}/delete', name: 'admin_project_delete', methods: ['GET', 'POST'])]
    #[IsGranted('delete', 'project')]
    public function deleteAction(Project $project, Request $request, ProjectStatisticService $statisticService): Response
    {
        $stats = $statisticService->getProjectStatistics($project);

        $deleteForm = $this->createFormBuilder(null, [
            'attr' => [
                'data-form-event' => 'kimai.projectDelete',
                'data-msg-success' => 'action.delete.success',
                'data-msg-error' => 'action.delete.error',
            ]
        ])
            ->add('project', ProjectType::class, [
                'ignore_project' => $project,
                'customers' => $project->getCustomer(),
                'query_builder_for_user' => true,
                'required' => false,
            ])
            ->setAction($this->generateUrl('admin_project_delete', ['id' => $project->getId()]))
            ->setMethod('POST')
            ->getForm();

        $deleteForm->handleRequest($request);

        if ($deleteForm->isSubmitted() && $deleteForm->isValid()) {
            try {
                $this->repository->deleteProject($project, $deleteForm->get('project')->getData());
                $this->flashSuccess('action.delete.success');
            } catch (\Exception $ex) {
                $this->flashDeleteException($ex);
            }

            return $this->redirectToRoute('admin_project');
        }

        return $this->render('project/delete.html.twig', [
            'page_setup' => $this->createPageSetup(),
            'project' => $project,
            'stats' => $stats,
            'form' => $deleteForm->createView(),
        ]);
    }

    #[Route(path: '/export', name: 'project_export', methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('listing', 'project')"))]
    public function exportAction(Request $request, EntityWithMetaFieldsExporter $exporter): Response
    {
        $query = new ProjectQuery();
        $query->setCurrentUser($this->getUser());

        $form = $this->getToolbarForm($query);
        $form->setData($query);
        $form->submit($request->query->all(), false);

        if (!$form->isValid()) {
            $query->resetByFormError($form->getErrors());
        }

        $entries = $this->repository->getProjectsForQuery($query);

        $spreadsheet = $exporter->export(
            Project::class,
            $entries,
            new ProjectMetaDisplayEvent($query, ProjectMetaDisplayEvent::EXPORT)
        );
        $writer = new BinaryFileResponseWriter(new XlsxWriter(), 'kimai-projects');

        return $writer->getFileResponse($spreadsheet);
    }

    private function getToolbarForm(ProjectQuery $query): FormInterface
    {
        return $this->createSearchForm(ProjectToolbarForm::class, $query, [
            'action' => $this->generateUrl('admin_project', [
                'page' => $query->getPage(),
            ]),
        ]);
    }

    private function getCommentForm(ProjectComment $comment): FormInterface
    {
        if (null === $comment->getId()) {
            $comment->setCreatedBy($this->getUser());
        }

        return $this->createForm(ProjectCommentForm::class, $comment, [
            'action' => $this->generateUrl('project_comment_add', ['id' => $comment->getProject()->getId()]),
            'method' => 'POST',
        ]);
    }

    private function createEditForm(Project $project): FormInterface
    {
        $event = new ProjectMetaDefinitionEvent($project);
        $this->dispatcher->dispatch($event);

        $currency = $this->configuration->getCustomerDefaultCurrency();
        $url = $this->generateUrl('admin_project_create');

        if ($project->getId() !== null) {
            $url = $this->generateUrl('admin_project_edit', ['id' => $project->getId()]);
            $currency = $project->getCustomer()->getCurrency();
        }

        return $this->createForm(ProjectEditForm::class, $project, [
            'action' => $url,
            'method' => 'POST',
            'currency' => $currency,
            'timezone' => $this->getDateTimeFactory()->getTimezone()->getName(),
            'include_budget' => $this->isGranted('budget', $project),
            'include_time' => $this->isGranted('time', $project),
        ]);
    }

    private function createPageSetup(): PageSetup
    {
        $page = new PageSetup('projects');
        $page->setHelp('project.html');

        return $page;
    }
}
