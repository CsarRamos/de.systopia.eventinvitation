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
use chillerlan\QRCode\QRCode;
use \Civi\EventMessages\MessageTokens;
use \Civi\EventMessages\MessageTokenList;


class CRM_Eventinvitation_EventInvitationCode
{
    // the three tokens we produce
    const TEMPLATE_CODE_TOKEN = 'qr_event_invite_code';
    const TEMPLATE_CODE_TOKEN_QR_DATA = 'qr_event_invite_code_data';
    const TEMPLATE_CODE_TOKEN_QR_IMG = 'qr_event_invite_code_img';

    const PARTICIPANT_CODE_USAGE = 'invite';



    public static function generate(string $participantId): string
    {
        $code = CRM_Remotetools_SecureToken::generateEntityToken(
            'Participant',
            $participantId,
            null,
            self::PARTICIPANT_CODE_USAGE
        );

        return $code;
    }

    /**
     * @return string|null The participant ID or, if invalid, null.
     */
    public static function validate(string $code)
    {
        $participantId = CRM_Remotetools_SecureToken::decodeEntityToken(
            'Participant',
            $code,
            self::PARTICIPANT_CODE_USAGE
        );

        return $participantId;
    }

    /**
     * Generate the tokens
     *
     * @param int $participantId
     *  the participant ID
     * @param int $event_id
     *  the event ID
     *
     * @return array
     *   list of token => value tuples
     */
    public static function getTemplateTokens(int $participantId, int $event_id): array
    {
        // collect some tokens for the
        $templateTokens = [];

        // get the invitation link
        $invitationCode = CRM_Eventinvitation_EventInvitationCode::generate($participantId);

        $settings = Civi::settings()->get(CRM_Eventinvitation_Form_Settings::SETTINGS_KEY);

        $link = '';
        if (
            is_array($settings)
            && array_key_exists(CRM_Eventinvitation_Form_Settings::LINK_TARGET_IS_CUSTOM_FORM_NAME, $settings)
            && array_key_exists(CRM_Eventinvitation_Form_Settings::CUSTOM_LINK_TARGET_FORM_NAME, $settings)
        ) {
            // get the link
            $link = $settings[CRM_Eventinvitation_Form_Settings::CUSTOM_LINK_TARGET_FORM_NAME];

            // replace the code token
            $link = preg_replace('/\{token\}/', $invitationCode, $link);

        } else {
            $path = 'civicrm/eventinvitation/register'; // NOTE: This must be adjusted if the URL in the menu XML is ever changed.

            $link = CRM_Utils_System::url($path, ['code' => $invitationCode], true, null);
        }
        $templateTokens[CRM_Eventinvitation_EventInvitationCode::TEMPLATE_CODE_TOKEN] = $link;

        // add a QR code
        if ($link) {
            try {
                $qr_code = new QRCode();
                $qr_code_data = $qr_code->render($link);
                $templateTokens[CRM_Eventinvitation_EventInvitationCode::TEMPLATE_CODE_TOKEN_QR_DATA] = $qr_code_data;
                $qr_code_alt_text = E::ts("Registration QR Code");
                $templateTokens[CRM_Eventinvitation_EventInvitationCode::TEMPLATE_CODE_TOKEN_QR_IMG] = "<img alt=\"{$qr_code_alt_text}\" src=\"{$qr_code_data}\"/>";
            } catch (Exception $ex) {
                Civi::log()->warning("Couldn't render QR code: " . $ex->getMessage());
            }
        }

        // add some event data
        static $event_data = null;
        if ($event_data === null) {
            if (!empty($event_id)) {
                try {
                    $event_data = civicrm_api3('Event', 'getsingle', ['id' => $event_id]);
                } catch (CiviCRM_API3_Exception $ex) {
                    $event_data = []; // don't look up again
                    Civi::log()->error("Error loading event [{$event_id}]: " . $ex->getMessage());
                }
            }
        }
        $templateTokens['event'] = $event_data;

        // that's it:
        return $templateTokens;
    }

    /**
     * Define/list the invitation code tokens
     *
     * @param MessageTokenList $tokenList
     *   token list event
     */
    public static function listTokens($tokenList)
    {
        $tokenList->addToken('$' . CRM_Eventinvitation_EventInvitationCode::TEMPLATE_CODE_TOKEN,
                             E::ts("Personalised invitation code (from EventInvitation extension). Use only if the participant has been invited."));
        $tokenList->addToken('$' . CRM_Eventinvitation_EventInvitationCode::TEMPLATE_CODE_TOKEN_QR_DATA,
                             E::ts("Personalised invitation QR code data (from EventInvitation extension). QR-Code data to be used in as <code>src</code> in an html <code>img</code> tag. Use only if the participant has been invited."));
        $tokenList->addToken('$' . CRM_Eventinvitation_EventInvitationCode::TEMPLATE_CODE_TOKEN_QR_IMG,
                             E::ts("Personalised invitation QR code data (from EventInvitation extension). QR-Code <code>img</code> element. Use only if the participant has been invited."));
    }

    /**
     * Define/list fill the invitation code tokens
     *
     * @param MessageTokens $messageTokens
     *   the token list
     */
    public static function addTokens(MessageTokens $messageTokens)
    {
        $tokens = $messageTokens->getTokens();
        if (!empty($tokens['participant']['id']) && !empty($tokens['participant']['event_id'])) {
            $participant_id = $tokens['participant']['id'];
            $tokens = self::getTemplateTokens($participant_id, $tokens['participant']['event_id']);

            foreach ([self::TEMPLATE_CODE_TOKEN, self::TEMPLATE_CODE_TOKEN_QR_DATA, self::TEMPLATE_CODE_TOKEN_QR_IMG] as $token) {
                if (isset($tokens[$token])) {
                    $messageTokens->setToken($token, $tokens[$token], false);
                }
            }
        }
    }

}
