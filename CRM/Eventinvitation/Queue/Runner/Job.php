<?php

/*-------------------------------------------------------+
| SYSTOPIA Event Invitation                              |
| Copyright (C) 2020 SYSTOPIA                            |
| Author: B. Zschiedrich (zschiedrich@systopia.de)       |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+-------------------------------------------------------*/

use CRM_Eventinvitation_ExtensionUtil as E;

abstract class CRM_Eventinvitation_Queue_Runner_Job
{
    /** @var string $title Will be set as title by the runner. */
    public $title;

    /** @var CRM_Eventinvitation_Object_RunnerData $runnerData */
    protected $runnerData;

    public function __construct(
        CRM_Eventinvitation_Object_RunnerData $runnerData,
        int $offset
    ) {
        $this->runnerData = $runnerData;

        $start = $offset + 1;
        $end = $offset + count($runnerData->contactIds);
        $this->title = E::ts('Processing contacts %1 to %2.', [1 => $start, 2 => $end]);
    }

    /**
     * This part will be implemented by the specific runners
     *
     * @param integer $contactId
     *   contact ID
     * @param array $templateTokens
     *   tokens
     *
     * @throws \CiviCRM_API3_Exception
     */
    protected abstract function processContact($contactId, $templateTokens);

    /**
     * Dispatch the contacts to the processContact function
     *
     * @return true
     */
    public function run(): bool
    {
        foreach ($this->runnerData->contactIds as $contactId) {
            $transaction = new CRM_Core_Transaction();

            try {
                $participantId = $this->setParticipantToInvited($contactId);
                $templateTokens = CRM_Eventinvitation_EventInvitationCode::getTemplateTokens(
                    $participantId, $this->runnerData->eventId);
                $this->processContact($contactId, $templateTokens);
            } catch (Exception $error) {
                $transaction->rollback();
                Civi::log()->warning("Generating email/pdf for contact {$contactId} failed: " . $error->getMessage());
            }

            $transaction->commit();
        }

        return true;
    }

    /**
     * Mark the contact to be 'Invited'.
     * Note that;
     *  - they might already be invited - in which case we do nothing
     *  - they might have already rejected, accepted, e.g. - in which case we also do nothing
     *
     * As a result: if there is already an existing participant for this contact/event, we do nothing.
     * @todo: do we want to upgrade an exisiting invitation, e.g. the date?
     *
     * @param string $contactId
     *   the contact that should be invited
     *
     * @return int
     * @throws \CiviCRM_API3_Exception
     */
    protected function setParticipantToInvited(string $contactId): int
    {
        // check if there is/are already existing participants
        $existing_participant = civicrm_api3(
            'Participant',
            'get',
            [
                'event_id' => $this->runnerData->eventId,
                'contact_id' => $contactId,
                'option.limit' => 1,
            ]
        );

        if (!empty($existing_participant['id'])) {
            // there is one, use that!
            return $existing_participant['id'];

        } else {
            // if there isn't one: create
            $queryResult = civicrm_api3(
                'Participant',
                'create',
                [
                    'event_id' => $this->runnerData->eventId,
                    'contact_id' => $contactId,
                    'status_id' => CRM_Eventinvitation_Upgrader::PARTICIPANT_STATUS_INVITED_NAME,
                    'role_id' => $this->runnerData->participantRoleId,
                ]
            );
            return $queryResult['id'];
        }
    }
}
