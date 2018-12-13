<?php
namespace PAGEmachine\Ats\Controller\Application;

/*
 * This file is part of the PAGEmachine ATS project.
 */


use PAGEmachine\Ats\Property\TypeConverter\UploadedFileReferenceConverter;
use PAGEmachine\Ats\Service\ExtconfService;
use PAGEmachine\Ats\Service\TyposcriptService;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\Controller\Exception\RequiredArgumentMissingException;

/**
 * AbstractApplicationController - handles access protection and redirects
 */
class AbstractApplicationController extends ActionController
{
    /**
     * applicationRepository
     *
     * @var \PAGEmachine\Ats\Domain\Repository\ApplicationRepository
     * @inject
     */
    protected $applicationRepository = null;


    /**
     * @var \PAGEmachine\Ats\Service\AuthenticationService
     * @inject
    */
    protected $authenticationService;

    /**
     * This is called before the form action and checks if a valid FE User is logged in.
     *
     * @return void
     */
    public function initializeAction()
    {
        // Merge TS and FlexForm settings
        $this->settings = TyposcriptService::getInstance()->mergeFlexFormAndTypoScriptSettings($this->settings);


        if ($this->request->hasArgument('application')) {
            $this->setPropertyMappingConfigurationForApplication();
            $this->loadValidationSettings();
        }

        $groupid = !empty($this->settings['feUserGroup']) ? $this->settings['feUserGroup'] : null;

        if (!$this->authenticationService->isUserAuthenticatedAndHasGroup($groupid)) {
            $arguments = $this->buildArgumentsForLoginHandling();

            //Create url to login page and send arguments
            $loginUri = $this->uriBuilder->reset()
                ->setTargetPageUid($this->settings['loginPage'])
                ->setArguments($arguments)
                ->build();

            $this->redirectToUri($loginUri);
        }
    }

    /**
     * Builds argument array to hand over when login redirect happens
     * Applications are not tracked. If there is a job and login user, the repository can reconstruct the application in progress
     *
     * @return array $arguments
     */
    protected function buildArgumentsForLoginHandling()
    {

        $job = null;

        if ($this->request->hasArgument("job")) {
            $job = $this->request->getArgument("job");
        } elseif ($this->request->hasArgument("application")) {
            $application = $this->request->getArgument("application");

            if (is_array($application) && array_key_exists("job", $application)) {
                $job = $application["job"];
            } elseif (is_numeric($application)) {
                $applicationObject = $this->applicationRepository->findByUid($application);

                $job = $applicationObject->getJob();
            }
        }
        if ($job === null) {
            throw new RequiredArgumentMissingException('Required argument "job" is not set for ' . $this->request->getControllerObjectName() . '->' . $this->request->getControllerActionName() . '.', 1298012500);
        }

        //Build forward and return url for login
        $arguments = [
            "return_url" => $this->uriBuilder->setCreateAbsoluteUri(true)->uriFor("form", ["job" => $job], "Application\\Form"),
            "referrer" => $this->uriBuilder->reset()->setCreateAbsoluteUri(true)->uriFor("show", ["job" => $job], "Job"),
        ];

        return $arguments;
    }

    /**
     * @return void
     */
    protected function setPropertyMappingConfigurationForApplication()
    {
        $this->arguments->getArgument('application')
            ->getPropertyMappingConfiguration()
            ->forProperty('birthday')
            ->setTypeConverterOption(\TYPO3\CMS\Extbase\Property\TypeConverter\DateTimeConverter::class, \TYPO3\CMS\Extbase\Property\TypeConverter\DateTimeConverter::CONFIGURATION_DATE_FORMAT, 'Y-m-d');

        $uploadConfiguration = ExtconfService::getInstance()->getUploadConfiguration();

        $this->arguments->getArgument('application')
            ->getPropertyMappingConfiguration()
            ->forProperty('files.999')
            ->setTypeConverterOptions(
                UploadedFileReferenceConverter::class,
                [
                    UploadedFileReferenceConverter::CONFIGURATION_UPLOAD_FOLDER => $uploadConfiguration['uploadFolder'],
                    UploadedFileReferenceConverter::CONFIGURATION_UPLOAD_CONFLICT_MODE => $uploadConfiguration['conflictMode'],
                    UploadedFileReferenceConverter::CONFIGURATION_FILE_EXTENSIONS => $uploadConfiguration['allowedFileExtensions'],
                ]
            );

        $this->arguments->getArgument('application')
            ->getPropertyMappingConfiguration()
            ->forProperty("languageSkills")->allowAllProperties()
            ->forProperty("languageSkills.*")->allowProperties("language", "level", "textLanguage")
            ->allowCreationForSubProperty('languageSkills.*');
    }

    /**
     * Loads validation settings into settings array to pass on to fluid
     *
     * @return void
     */
    protected function loadValidationSettings()
    {
        $this->settings['validation'] = TyposcriptService::getInstance()->getFrameworkConfiguration()['mvc']['validation'][$this->arguments->getArgument('application')->getDataType()];
    }
}
