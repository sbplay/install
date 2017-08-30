<?php
declare(strict_types=1);
namespace TYPO3\CMS\Install\Controller\Action\Ajax;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Install\Service\UpgradeWizardsService;

/**
 * Get a list of wizards and row updaters marked as "done" in registry
 */
class UpgradeWizardsDoneUpgrades extends AbstractAjaxAction
{
    /**
     * Executes the action
     *
     * @return array Rendered content
     */
    protected function executeAction(): array
    {
        $this->loadExtLocalconfDatabaseAndExtTables();

        $upgradeWizardsService = new UpgradeWizardsService();
        $wizardsDone = $upgradeWizardsService->listOfWizardsDoneInRegistry();
        $rowUpdatersDone = $upgradeWizardsService->listOfRowUpdatersDoneInRegistry();

        $messages = new FlashMessageQueue('install');
        if (empty($wizardsDone) && empty($rowUpdatersDone)) {
            $messages->enqueue(new FlashMessage(
                '',
                'No wizards are marked as done'
            ));
        }

        $this->view->assignMultiple([
            'success' => true,
            'status' => $messages,
            'wizardsDone' => $wizardsDone,
            'rowUpdatersDone' => $rowUpdatersDone,
        ]);
        return $this->view->render();
    }
}
