<?php
/* Copyright (C) 2025 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    invoicestatus/class/actions_invoicestatus.class.php
 * \ingroup invoicestatus
 * \brief   Example hook overload.
 *
 * Put detailed description here.
 */

/**
 * Class ActionsInvoiceStatus
 */
class ActionsInvoiceStatus
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();


	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}
	
	public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $user, $db;
	    if (get_class($object) == 'Facture' && isset($user->rights->invoicestatus->modifierstatut))
        {
            if ($action == "modif_status")
            {
        	    $invoice_id = GETPOST('invoice_id', 'int');
                $new_status = GETPOST('new_status', 'int');
        	    $invoice = new Facture($db);
                if ($invoice->fetch($invoice_id) > 0) 
                {
                    $db->begin();
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "facture SET fk_statut = " . (int)$new_status . " WHERE rowid = " . (int)$invoice_id;
                    $res = $db->query($sql);
                    error_log($sql);
                    if ($res) {
                        $db->commit();
                        setEventMessages($langs->trans("InvoiceStatusChanged"), null, 'mesgs');
                    } else {
                        print $res;
                        $db->rollback();
                        setEventMessages($sql, null, 'errors');
                    }
                }
            }
        }
        return 0;
    }
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $user, $db, $form;
        if (get_class($object) == 'Facture' && isset($user->rights->invoicestatus->modifierstatut))
        {
            $token = newToken();
            $selected = array("", "", "", "", "", "", "");
            $selected[$object->status] = "selected";
            print '<form method="post" action="'.dol_buildpath('/compta/facture/card.php?facid=' . $object->id . '&action=modif_status&token=' . $token,1).'" id="status_change_form">
                <input type="hidden" name="invoice_id" value="' . $object->id . '">
                <select name="new_status">
                    <option ' . $selected[0] .' value="0">Brouillon</option>
                    <option ' . $selected[1] .' value="1">Impayée</option>
                    <option ' . $selected[2] .' value="2">Payée</option>
                    <option ' . $selected[3] .' value="3">Abandonnée</option>
                    <option ' . $selected[4] .' value="4">En cours de prélèvement</option>
                    <option ' . $selected[5] .' value="5">Factor : impayé</option>
                    <option ' . $selected[6] .' value="6">Factor : payé</option>
                </select>
                <input class="button" type="submit" value="Changer l\'état" id="submit_button">
            </form>';
        }
        return 0;
    }
}
