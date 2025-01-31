<?php
/* Copyright (C) 2015   Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2019 David de imarotulos.com <david@imarotulos.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
/**
 * API class for customfeature
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class customfeature extends DolibarrApi
{


   

	/**
	 * @var Societe $company {@type Societe}
	 */
	public $company;

    /**
	 * @var Propal $propal {@type Propal}
	 */
	public $propal;


    /**
	 * @var Commande $commande {@type Commande}
	 */
	public $commande;



    /**
     * Constructor
     *
     * @url     GET /
     *
     */
    public function __construct()
    {
        global $db, $conf;
        $this->db = $db;

        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societeaccount.class.php';
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';

		$this->company = new Societe($this->db);

		if (!empty($conf->global->SOCIETE_EMAIL_MANDATORY)) {
			static::$FIELDS[] = 'email';
		}

        $this->propal = new Propal($this->db);
        $this->commande = new Commande($this->db);

    }



    


    /**
	 * Get fixed amount discount of a thirdparty (all sources: deposit, credit note, commercial offers...)
	 *
	 * @param 	int 	$id             ID of the thirdparty
	 * @param 	string 	$filter    	Filter exceptional discount. "none" will return every discount, "available" returns unapplied discounts, "used" returns applied discounts   {@choice none,available,used}
	 * @param   string  $sortfield  	Sort field
	 * @param   string  $sortorder  	Sort order
	 *
	 * @url     GET thirdparties/{id}/getFixedAmountDiscountsWithoutInvoice
	 *
	 * @return array  List of fixed discount of thirdparty
	 *
	 * @throws RestException 400
	 * @throws RestException 401
	 * @throws RestException 404
	 * @throws RestException 503
	 */
	public function getFixedAmountDiscountsWithoutInvoice($id, $filter = "none", $sortfield = "f.type", $sortorder = 'ASC')
	{
		$obj_ret = array();

		if (!DolibarrApiAccess::$user->rights->societe->lire) {
			throw new RestException(401);
		}

		if (empty($id)) {
			throw new RestException(400, 'Thirdparty ID is mandatory');
		}

		if (!DolibarrApi::_checkAccessToResource('societe', $id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		$result = $this->company->fetch($id);
		if (!$result) {
			throw new RestException(404, 'Thirdparty not found');
		}


		$sql = "SELECT re.fk_facture_source, re.rowid, re.amount_ht, re.amount_tva, re.amount_ttc, re.description, re.fk_facture, re.fk_facture_line, re.tva_tx";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe_remise_except as re";
		$sql .= " WHERE re.fk_soc = ".((int) $id);
		if ($filter == "available") {
			$sql .= " AND re.fk_facture IS NULL AND re.fk_facture_line IS NULL";
		}
		if ($filter == "used") {
			$sql .= " AND (re.fk_facture IS NOT NULL OR re.fk_facture_line IS NOT NULL)";
		}

		//$sql .= $this->db->order($sortfield, $sortorder);

		$result = $this->db->query($sql);
		if (!$result) {
			throw new RestException(503, $this->db->lasterror());
		} else {
			$num = $this->db->num_rows($result);
			while ($obj = $this->db->fetch_object($result)) {
				$obj_ret[] = $obj;
			}
		}

		return $obj_ret;
	}





    






    /**
	 * List products
	 *
	 * Get a list of products
	 *
	 * @param  string $sortfield  			Sort field
	 * @param  string $sortorder  			Sort order
	 * @param  int    $limit      			Limit for list
	 * @param  int    $page       			Page number
	 * @param  int    $mode       			Use this param to filter list (0 for all, 1 for only product, 2 for only service)
	 * @param  int    $category   			Use this param to filter list by category
	 * @param  string $sqlfilters 			Other criteria to filter answers separated by a comma. Syntax example "(t.tobuy:=:0) and (t.tosell:=:1)"
	 * @param  bool   $ids_only   			Return only IDs of product instead of all properties (faster, above all if list is long)
	 * @param  int    $variant_filter   	Use this param to filter list (0 = all, 1=products without variants, 2=parent of variants, 3=variants only)
	 * @param  bool   $pagination_data   	If this parameter is set to true the response will include pagination data. Default value is false. Page starts from 0
	 * @param  int    $includestockdata		Load also information about stock (slower)
     * @url     GET products
	 * @return array                		Array of product objects
	 */
	public function indexproduct($sortfield = "t.ref", $sortorder = 'ASC', $limit = 100, $page = 0, $mode = 0, $category = 0, $sqlfilters = '', $ids_only = false, $variant_filter = 0, $pagination_data = false, $includestockdata = 0, $label_filter = '')
	{
		global $db, $conf;

		if (!DolibarrApiAccess::$user->rights->produit->lire) {
			throw new RestException(403);
		}

		$obj_ret = array();

		$socid = DolibarrApiAccess::$user->socid ? DolibarrApiAccess::$user->socid : '';
		$sql = "SELECT t.rowid, t.ref, t.ref_ext, f.share";
        $sql .= " FROM ".$this->db->prefix()."product as t";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields AS ef ON ef.fk_object = t.rowid";   // So we will be able to filter on extrafields
        $sql .= " LEFT JOIN (";
        $sql .= "     SELECT src_object_id, src_object_type, share";
        $sql .= "     FROM ".MAIN_DB_PREFIX."ecm_files";
        $sql .= "     GROUP BY src_object_id, src_object_type";
        $sql .= " ) as f ON f.src_object_id = t.rowid AND f.src_object_type = 'product'";


		/*$sql = "SELECT t.rowid, t.ref, t.ref_ext, f.share";
		$sql .= " FROM ".$this->db->prefix()."product as t";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields AS ef ON ef.fk_object = t.rowid";	// So we will be able to filter on extrafields
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."ecm_files as f ON f.src_object_id = t.rowid AND f.src_object_type = 'product'";*/
		// Apply category filter
        if ($category > 0) {
	       $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie_product AS c ON t.rowid = c.fk_product";
        }

        // Filter on entity
        $sql .= ' WHERE t.entity IN ('.getEntity('product').')';
        //$sql .= ' AND f.share IS NOT NULL';

        // Add label filter if specified
        if (!empty($label_filter)) {
           $sql .= ' AND t.label LIKE \'%' . $this->db->escape($label_filter) . '%\'';
        }




		if ($variant_filter == 1) {
			$sql .= ' AND t.rowid not in (select distinct fk_product_parent from '.$this->db->prefix().'product_attribute_combination)';
			$sql .= ' AND t.rowid not in (select distinct fk_product_child from '.$this->db->prefix().'product_attribute_combination)';
		}
		if ($variant_filter == 2) {
			$sql .= ' AND t.rowid in (select distinct fk_product_parent from '.$this->db->prefix().'product_attribute_combination)';
		}
		if ($variant_filter == 3) {
			$sql .= ' AND t.rowid in (select distinct fk_product_child from '.$this->db->prefix().'product_attribute_combination)';
		}

		// Select products of given category
		if ($category > 0) {
			$sql .= " AND c.fk_categorie = ".((int) $category);
			$sql .= " AND c.fk_product = t.rowid";
		}
		if ($mode == 1) {
			// Show only products
			$sql .= " AND t.fk_product_type = 0";
		} elseif ($mode == 2) {
			// Show only services
			$sql .= " AND t.fk_product_type = 1";
		}

		// Add sql filters
		if ($sqlfilters) {
			// Add a join to the category_product table to enable filtering by category
           //$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie_product AS c ON t.rowid = c.fk_product";
			$errormessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
			if ($errormessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
		}

		//this query will return total products with the filters given
		$sqlTotals =  str_replace('SELECT t.rowid, t.ref, t.ref_ext', 'SELECT count(t.rowid) as total', $sql);

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;

			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		$result = $this->db->query($sql);
		if ($result) {
			$num = $this->db->num_rows($result);
			$min = min($num, ($limit <= 0 ? $num : $limit));
			$i = 0;
			while ($i < $min) {
				$obj = $this->db->fetch_object($result);
				if (!$ids_only) {
					$product_static = new Product($this->db);
					if ($product_static->fetch($obj->rowid)) {
						//$product_static->public_key = $obj->share;
						if (!empty($includestockdata) && DolibarrApiAccess::$user->rights->stock->lire) {
							$product_static->load_stock();

							if (is_array($product_static->stock_warehouse)) {
								foreach ($product_static->stock_warehouse as $keytmp => $valtmp) {
									if (isset($product_static->stock_warehouse[$keytmp]->detail_batch) && is_array($product_static->stock_warehouse[$keytmp]->detail_batch)) {
										foreach ($product_static->stock_warehouse[$keytmp]->detail_batch as $keytmp2 => $valtmp2) {
											unset($product_static->stock_warehouse[$keytmp]->detail_batch[$keytmp2]->db);
										}
									}
								}
							}
						}
						// Ajouter les URLs des photos 
                        //$product_static->url_photo = DOL_MAIN_URL_ROOT . '/document.php?hashp=' 
                            //. $product_static->public_key;

						//echo $product_static->rowid;
						// Ajouter les URLs des photos 
                        /*$product_static->url_photo = DOL_MAIN_URL_ROOT . '/document.php?modulepart=produit&attachment=0&file=' 
                            . substr($product_static->id, -1) . '/' 
                            . substr($product_static->id, -2, 1) . '/' 
                            . $product_static->id . '/photos/' 
                            . $product_static->id . '.jpg' 
                            . '&entity=1';*/

                       // Filtrez les données du produit ici.
                       $product_data = array(
                        'id' => $product_static->id,
                        'ref' => $product_static->ref,
                        'label' => $product_static->label,
                        'price' => $product_static->price,
                        'weight' => $product_static->weight,
                        'price_ttc' => $product_static->price_ttc,
                        'entity' => $product_static->entity,
                        'public_key' => $obj->share,
                        'desired_stock' => $product_static->desired_stock,
                        'seuil_stock_alert' => $product_static->seuil_stock_alert,
                        'import_key' => $product_static->import_key,
                        'url_photo' => DOL_MAIN_URL_ROOT . '/document.php?hashp=' 
                            . $obj->share,
						'tva_tx' => $product_static->tva_tx,
                        // Ajoutez ici tous les autres champs dont vous avez besoin.
                         );
                    $obj_ret[] = $product_data;


						//$product_static->share = $obj->share;
						//$obj_ret[] = $this->_cleanObjectDatas($product_static);
					}
				} else {
					$obj_ret[] = $obj->rowid;
				}
				$i++;
			}
		} else {
			throw new RestException(503, 'Error when retrieve product list : '.$this->db->lasterror());
		}
		if (!count($obj_ret)) {
			throw new RestException(404, 'No product found');
		}

		//if $pagination_data is true the response will contain element data with all values and element pagination with pagination data(total,page,limit)
		if ($pagination_data) {
			$totalsResult = $this->db->query($sqlTotals);
			$total = $this->db->fetch_object($totalsResult)->total;

			$tmp = $obj_ret;
			$obj_ret = array();

			$obj_ret['data'] = $tmp;
			$obj_ret['pagination'] = array(
				'total' => (int) $total,
				'page' => $page, //count starts from 0
				'page_count' => ceil((int) $total/$limit),
				'limit' => $limit
			);
		}

		return $obj_ret;
	}






















    /**
	 * Get properties of a commercial proposal object
	 *
	 * Return an array with commercial proposal informations
	 *
	 * @param       int         $id           ID of commercial proposal
	 * @param       int         $contact_list 0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
     * @url GET    proposals/{id}
	 * @return 	array|mixed data without useless information
	 *
	 * @throws 	RestException
	 */
	public function getproposal($id, $contact_list = 1)
	{
		return $this->_fetchpropal($id, '', '', $contact_list);
	}

	/**
	 * Get properties of an proposal object by ref
	 *
	 * Return an array with proposal informations
	 *
	 * @param       string		$ref			Ref of object
	 * @param       int         $contact_list  0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
	 * @return 	array|mixed data without useless information
	 *
	 * @url GET    proposals/ref/{ref}
	 *
	 * @throws 	RestException
	 */
	public function getByRefproposal($ref, $contact_list = 1)
	{
		return $this->_fetchpropal('', $ref, '', $contact_list);
	}

	/**
	 * Get properties of an proposal object by ref_ext
	 *
	 * Return an array with proposal informations
	 *
	 * @param       string		$ref_ext			External reference of object
	 * @param       int         $contact_list  0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
	 * @return 	array|mixed data without useless information
	 *
	 * @url GET    proposals/ref_ext/{ref_ext}
	 *
	 * @throws 	RestException
	 */
	public function getByRefExtproposal($ref_ext, $contact_list = 1)
	{
		return $this->_fetchpropal('', '', $ref_ext, $contact_list);
	}





    /**
	 * Get properties of an proposal object
	 *
	 * Return an array with proposal informations
	 *
	 * @param       int         $id             ID of order
	 * @param		string		$ref			Ref of object
	 * @param		string		$ref_ext		External reference of object
	 * @param       int         $contact_list  0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
	 * @return 	array|mixed data without useless information
	 *
	 * @throws 	RestException
	 */
	private function _fetchpropal($id, $ref = '', $ref_ext = '', $contact_list = 1)
	{
		if (!DolibarrApiAccess::$user->rights->propal->lire) {
			throw new RestException(401);
		}

		$result = $this->propal->fetch($id, $ref, $ref_ext);
		if (!$result) {
			throw new RestException(404, 'Commercial Proposal not found');
		}

		if (!DolibarrApi::_checkAccessToResource('propal', $this->propal->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		// Add external contacts ids.
		$tmparray = $this->propal->liste_contact(-1, 'external', $contact_list);
		if (is_array($tmparray)) {
			$this->propal->contacts_ids = $tmparray;
		}

		$this->propal->fetchObjectLinked();
		// Parcourt chaque produit et ajoute l'URL de l'image
        if (isset($this->propal->lines) && is_array($this->propal->lines)) {
        foreach ($this->propal->lines as $line) {
            if ($line->fk_product) {
                $product = new Product($this->db);
                if ($product->fetch($line->fk_product)) {
                    $product->url_photo = DOL_MAIN_URL_ROOT . '/document.php?modulepart=produit&attachment=0&file=' 
                        . substr($product->id, -1) . '/' 
                        . substr($product->id, -2, 1) . '/' 
                        . $product->id . '/photos/' 
                        . $product-> DOL_MAIN_URL_ROOT . '/document.php?hashp=' 
                            . $obj->share;
                    $line->product_image_url = $product->url_photo;
                }
            }
        }
        }

		return $this->_cleanObjectDatas($this->propal);
	}





    /**
	 * List commercial proposals
	 *
	 * Get a list of commercial proposals
	 *
	 * @param string	$sortfield	        Sort field
	 * @param string	$sortorder	        Sort order
	 * @param int		$limit		        Limit for list
	 * @param int		$page		        Page number
	 * @param string   	$thirdparty_ids	    Thirdparty ids to filter commercial proposals (example '1' or '1,2,3') {@pattern /^[0-9,]*$/i}
	 * @param string    $sqlfilters         Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.datec:<:'20160101')"
     * @url     GET proposals
	 * @return  array                       Array of order objects
	 */
	public function indexproposal($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $thirdparty_ids = '', $sqlfilters = '')
	{
		global $db, $conf;

		if (!DolibarrApiAccess::$user->rights->propal->lire) {
			throw new RestException(401);
		}

		$obj_ret = array();

		// case of external user, $thirdparty_ids param is ignored and replaced by user's socid
		$socids = DolibarrApiAccess::$user->socid ? DolibarrApiAccess::$user->socid : $thirdparty_ids;

		// If the internal user must only see his customers, force searching by him
		$search_sale = 0;
		if (!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) {
			$search_sale = DolibarrApiAccess::$user->id;
		}

		$sql = "SELECT t.rowid";
		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) {
			$sql .= ", sc.fk_soc, sc.fk_user"; // We need these fields in order to filter by sale (including the case where the user can only see his prospects)
		}
		$sql .= " FROM ".MAIN_DB_PREFIX."propal as t";

		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) {
			$sql .= ", ".MAIN_DB_PREFIX."societe_commerciaux as sc"; // We need this table joined to the select in order to filter by sale
		}

		$sql .= ' WHERE t.entity IN ('.getEntity('propal').')';
		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) {
			$sql .= " AND t.fk_soc = sc.fk_soc";
		}
		if ($socids) {
			$sql .= " AND t.fk_soc IN (".$this->db->sanitize($socids).")";
		}
		if ($search_sale > 0) {
			$sql .= " AND t.rowid = sc.fk_soc"; // Join for the needed table to filter by sale
		}
		// Insert sale filter
		if ($search_sale > 0) {
			$sql .= " AND sc.fk_user = ".((int) $search_sale);
		}
		// Add sql filters
		if ($sqlfilters) {
			$errormessage = '';
			if (!DolibarrApi::_checkFilters($sqlfilters, $errormessage)) {
				throw new RestException(503, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
			$regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^\(\)]+)\)';
			$sql .= " AND (".preg_replace_callback('/'.$regexstring.'/', 'DolibarrApi::_forge_criteria_callback', $sqlfilters).")";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;

			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		dol_syslog("API Rest request");
		$result = $this->db->query($sql);

		if ($result) {
			$num = $this->db->num_rows($result);
			$min = min($num, ($limit <= 0 ? $num : $limit));
			$i = 0;
			while ($i < $min) {
				$obj = $this->db->fetch_object($result);
				$proposal_static = new Propal($this->db);
				if ($proposal_static->fetch($obj->rowid)) {
					// Add external contacts ids
					$tmparray = $proposal_static->liste_contact(-1, 'external', 1);
					if (is_array($tmparray)) {
						$proposal_static->contacts_ids = $tmparray;
					}
					$obj_ret[] = $this->_cleanObjectDatas($proposal_static);
				}
				$i++;
			}
		} else {
			throw new RestException(503, 'Error when retrieve propal list : '.$this->db->lasterror());
		}
		if (!count($obj_ret)) {
			throw new RestException(404, 'No proposal found');
		}
		return $obj_ret;
	}




















    /**
	 * Get properties of an order object by id
	 *
	 * Return an array with order informations
	 *
	 * @param       int         $id            ID of order
	 * @param       int         $contact_list  0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
     * @url GET    orders/{id}
	 * @return 	array|mixed data without useless information
	 *
	 * @throws 	RestException
	 */
	public function getorder($id, $contact_list = 1)
	{
		return $this->_fetchorder($id, '', '', $contact_list);
	}

	/**
	 * Get properties of an order object by ref
	 *
	 * Return an array with order informations
	 *
	 * @param       string		$ref			Ref of object
	 * @param       int         $contact_list  0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
	 * @return 	array|mixed data without useless information
	 *
	 * @url GET    orders/ref/{ref}
	 *
	 * @throws 	RestException
	 */
	public function getByReforder($ref, $contact_list = 1)
	{
		return $this->_fetchorder('', $ref, '', $contact_list);
	}

	/**
	 * Get properties of an order object by ref_ext
	 *
	 * Return an array with order informations
	 *
	 * @param       string		$ref_ext			External reference of object
	 * @param       int         $contact_list  0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
	 * @return 	array|mixed data without useless information
	 *
	 * @url GET    orders/ref_ext/{ref_ext}
	 *
	 * @throws 	RestException
	 */
	public function getByRefExtorder($ref_ext, $contact_list = 1)
	{
		return $this->_fetchorder('', '', $ref_ext, $contact_list);
	}






    /**
	 * Get properties of an order object
	 *
	 * Return an array with order informations
	 *
	 * @param       int         $id            ID of order
	 * @param		string		$ref			Ref of object
	 * @param		string		$ref_ext		External reference of object
	 * @param       int         $contact_list  0: Returned array of contacts/addresses contains all properties, 1: Return array contains just id
	 * @return 	array|mixed data without useless information
	 *
	 * @throws 	RestException
	 */
	private function _fetchorder($id, $ref = '', $ref_ext = '', $contact_list = 1)
	{
		if (!DolibarrApiAccess::$user->rights->commande->lire) {
			throw new RestException(401);
		}

		$result = $this->commande->fetch($id, $ref, $ref_ext);
		if (!$result) {
			throw new RestException(404, 'Order not found');
		}

		if (!DolibarrApi::_checkAccessToResource('commande', $this->commande->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		// Add external contacts ids
		$tmparray = $this->commande->liste_contact(-1, 'external', $contact_list);
		if (is_array($tmparray)) {
			$this->commande->contacts_ids = $tmparray;
		}
		$this->commande->fetchObjectLinked();
		// Parcourt chaque produit et ajoute l'URL de l'image
        if (isset($this->commande->lines) && is_array($this->commande->lines)) {
        foreach ($this->commande->lines as $line) {
            if ($line->fk_product) {
                $product = new Product($this->db);
                if ($product->fetch($line->fk_product)) {
                    $product->url_photo = DOL_MAIN_URL_ROOT . '/document.php?modulepart=produit&attachment=0&file=' 
                        . substr($product->id, -1) . '/' 
                        . substr($product->id, -2, 1) . '/' 
                        . $product->id . '/photos/' 
                        . $product-> DOL_MAIN_URL_ROOT . '/document.php?hashp=' 
                            . $obj->share;
                    $line->product_image_url = $product->url_photo;
                }
            }
        }
        }
		

		// Add online_payment_url, cf #20477
		require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
		$this->commande->online_payment_url = getOnlinePaymentUrl(0, 'order', $this->commande->ref);

		return $this->_cleanObjectDatas($this->commande);
	}



    /**
	 * List orders
	 *
	 * Get a list of orders
	 *
	 * @param string	       $sortfield	        Sort field
	 * @param string	       $sortorder	        Sort order
	 * @param int		       $limit		        Limit for list
	 * @param int		       $page		        Page number
	 * @param string   	       $thirdparty_ids	    Thirdparty ids to filter orders of (example '1' or '1,2,3') {@pattern /^[0-9,]*$/i}
	 * @param string           $sqlfilters          Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.date_creation:<:'20160101')"
     * @url     GET orders
	 * @return  array                               Array of order objects
	 *
	 * @throws RestException 404 Not found
	 * @throws RestException 503 Error
	 */
	public function indexorder($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $thirdparty_ids = '', $sqlfilters = '')
	{
		global $db, $conf;

		if (!DolibarrApiAccess::$user->rights->commande->lire) {
			throw new RestException(401);
		}

		$obj_ret = array();

		// case of external user, $thirdparty_ids param is ignored and replaced by user's socid
		$socids = DolibarrApiAccess::$user->socid ? DolibarrApiAccess::$user->socid : $thirdparty_ids;

		// If the internal user must only see his customers, force searching by him
		$search_sale = 0;
		if (!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) {
			$search_sale = DolibarrApiAccess::$user->id;
		}

		$sql = "SELECT t.rowid";
		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) {
			$sql .= ", sc.fk_soc, sc.fk_user"; // We need these fields in order to filter by sale (including the case where the user can only see his prospects)
		}
		$sql .= " FROM ".MAIN_DB_PREFIX."commande as t";

		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) {
			$sql .= ", ".MAIN_DB_PREFIX."societe_commerciaux as sc"; // We need this table joined to the select in order to filter by sale
		}

		$sql .= ' WHERE t.entity IN ('.getEntity('commande').')';
		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socids) || $search_sale > 0) {
			$sql .= " AND t.fk_soc = sc.fk_soc";
		}
		if ($socids) {
			$sql .= " AND t.fk_soc IN (".$this->db->sanitize($socids).")";
		}
		if ($search_sale > 0) {
			$sql .= " AND t.rowid = sc.fk_soc"; // Join for the needed table to filter by sale
		}
		// Insert sale filter
		if ($search_sale > 0) {
			$sql .= " AND sc.fk_user = ".((int) $search_sale);
		}
		// Add sql filters
		if ($sqlfilters) {
			$errormessage = '';
			if (!DolibarrApi::_checkFilters($sqlfilters, $errormessage)) {
				throw new RestException(503, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
			$regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^\(\)]+)\)';
			$sql .= " AND (".preg_replace_callback('/'.$regexstring.'/', 'DolibarrApi::_forge_criteria_callback', $sqlfilters).")";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;

			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		dol_syslog("API Rest request");
		$result = $this->db->query($sql);

		if ($result) {
			$num = $this->db->num_rows($result);
			$min = min($num, ($limit <= 0 ? $num : $limit));
			$i = 0;
			while ($i < $min) {
				$obj = $this->db->fetch_object($result);
				$commande_static = new Commande($this->db);
				if ($commande_static->fetch($obj->rowid)) {
					// Add external contacts ids
					$tmparray = $commande_static->liste_contact(-1, 'external', 1);
					if (is_array($tmparray)) {
						$commande_static->contacts_ids = $tmparray;
					}
					// Add online_payment_url, cf #20477
					require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
					$commande_static->online_payment_url = getOnlinePaymentUrl(0, 'order', $commande_static->ref);

					$obj_ret[] = $this->_cleanObjectDatas($commande_static);
				}
				$i++;
			}
		} else {
			throw new RestException(503, 'Error when retrieve commande list : '.$this->db->lasterror());
		}
		if (!count($obj_ret)) {
			throw new RestException(404, 'No order found');
		}
		return $obj_ret;
	}



















    /**
	 * List categories
	 *
	 * Get a list of categories
	 *
	 * @param string	$sortfield	Sort field
	 * @param string	$sortorder	Sort order
	 * @param int		$limit		Limit for list
	 * @param int		$page		Page number
	 * @param string	$type		Type of category ('member', 'customer', 'supplier', 'product', 'contact')
	 * @param string    $sqlfilters Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.date_creation:<:'20160101')"
     * @url     GET categories
	 * @return array                Array of category objects
	 *
	 * @throws RestException
	 */
	public function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $type = '', $sqlfilters = '')
	{
		global $db, $conf;

		$obj_ret = array();

		if (!DolibarrApiAccess::$user->rights->categorie->lire) {
			throw new RestException(401);
		}

		$sql = "SELECT t.rowid, t.fk_parent";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie as t";
		$sql .= ' WHERE t.entity IN ('.getEntity('category').')';
		if (!empty($type)) {
			$sql .= ' AND t.type='.array_search($type, Categories::$TYPES);
		}
		// Add sql filters
		if ($sqlfilters) {
			$errormessage = '';
			if (!DolibarrApi::_checkFilters($sqlfilters, $errormessage)) {
				throw new RestException(503, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
			$regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^\(\)]+)\)';
			$sql .= " AND (".preg_replace_callback('/'.$regexstring.'/', 'DolibarrApi::_forge_criteria_callback', $sqlfilters).")";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$offset = $limit * $page;

			$sql .= $this->db->plimit($limit + 1, $offset);
		}

		$result = $this->db->query($sql);
		if ($result) {
			$i = 0;
			$num = $this->db->num_rows($result);
			$min = min($num, ($limit <= 0 ? $num : $limit));
			$categories_by_parent = array();
			while ($i < $min) {
				$obj = $this->db->fetch_object($result);
				$category_static = new Categorie($this->db);
				if ($category_static->fetch($obj->rowid)) {
					$category_data = $this->_cleanObjectDatas($category_static);
                    $categories_by_parent[$obj->fk_parent][] = $category_data;
				}
				$i++;
			}
		} else {
			throw new RestException(503, 'Error when retrieve category list : '.$this->db->lasterror());
		}

		$structured_categories = array();
		if (isset($categories_by_parent[0])) {
        foreach ($categories_by_parent[0] as $parent_category) {
        $category_data = array(
            'fk_parent' => $parent_category->fk_parent,
            'label' => $parent_category->label,
            'description' => $parent_category->description,
            'color' => $parent_category->color,
            'visible' => $parent_category->visible,
            'type' => $parent_category->type,
            'id' => $parent_category->id,
            'entity' => $parent_category->entity,
            'user_creation' => $parent_category->user_creation,
            'user_creation_id' => $parent_category->user_creation_id,
            'user_modification' => $parent_category->user_modification,
            'user_modification_id' => $parent_category->user_modification_id,
            'date_creation' => $parent_category->date_creation,
            'date_modification' => $parent_category->date_modification,
            'subcategories' => $categories_by_parent[$parent_category->id] ?? []
        );
        $structured_categories[] = $category_data;
        }
        }
        
		if (!count($structured_categories)) {
			throw new RestException(404, 'No category found');
		}
		return $structured_categories;
	}





    












    private function _validate($data)
    {
        $object = array();
        foreach (self::$FIELDS as $field) {
            if (!isset($data[$field])) {
                throw new RestException(400, "$field field missing");
            }

            $object[$field] = $data[$field];
        }
        return $object;
    }











    /**
	 * List orders
	 *
	 * Get a list of orders
	 *
	 * @param string	       $sortfield	        Sort field
	 * @param string	       $sortorder	        Sort order
	 * @param int		       $limit		        Limit for list
	 * @param int		       $page		        Page number
	 * @param string   	       $thirdparty_ids	    Thirdparty ids to filter orders of (example '1' or '1,2,3') {@pattern /^[0-9,]*$/i}
	 * @param string           $sqlfilters          Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.date_creation:<:'20160101')"
     * @url     GET orderWaitingToBeDelivered
	 * @return  array                               Array of order objects
	 *
	 * @throws RestException 404 Not found
	 * @throws RestException 503 Error
	 */
	public function indexOrderWaitingToBeDelivered($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $thirdparty_ids = '', $sqlfilters = '')
	{
		global $db, $conf;



		if (!DolibarrApiAccess::$user->rights->commande->lire) {
			throw new RestException(401);
		}

		$start = strtotime(date("Y-m-d", strtotime("-10 day")));

		$obj_ret = array();

		$sqlValide .= "SELECT t.*,t.rowid as commandeId, t.ref as commandeRef, u.firstname, u.lastname, p.ref as devis, s.nom as resto, s.address as address, s.zip as zip, s.town as town, s.phone as phone, ce.livreur as livreur 
		FROM ".MAIN_DB_PREFIX."commande as t 
		LEFT JOIN ".MAIN_DB_PREFIX."societe s on t.fk_soc = s.rowid 
		LEFT JOIN ".MAIN_DB_PREFIX."element_element ee on t.rowid = ee.fk_target
		LEFT JOIN ".MAIN_DB_PREFIX."propal p on p.rowid = ee.fk_source
		LEFT JOIN ".MAIN_DB_PREFIX."societe_commerciaux sc on t.fk_soc = sc.fk_soc 
		LEFT JOIN ".MAIN_DB_PREFIX."user u on u.rowid = sc.fk_user 
		LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ce on ce.fk_object = t.rowid
		
		WHERE t.date_creation >  DATE_SUB(NOW(), INTERVAL 10 DAY) AND t.fk_statut = 1 AND targettype LIKE 'commande'";

		$sqlEnCours .= "SELECT t.*,t.rowid as commandeId, t.ref as commandeRef, u.firstname, u.lastname, p.ref as devis, s.nom as resto, s.address as address, s.zip as zip, s.town as town, s.phone as phone, ce.livreur as livreur, ce.etatdepreparation as etatdepreparation, ce.produitsmanquants as produitsmanquants  
		FROM ".MAIN_DB_PREFIX."commande as t 
		LEFT JOIN ".MAIN_DB_PREFIX."societe s on t.fk_soc = s.rowid 
		LEFT JOIN ".MAIN_DB_PREFIX."element_element ee on t.rowid = ee.fk_target
		LEFT JOIN ".MAIN_DB_PREFIX."propal p on p.rowid = ee.fk_source
		LEFT JOIN ".MAIN_DB_PREFIX."societe_commerciaux sc on t.fk_soc = sc.fk_soc 
		LEFT JOIN ".MAIN_DB_PREFIX."user u on u.rowid = sc.fk_user 
		LEFT JOIN ".MAIN_DB_PREFIX."commande_extrafields ce on ce.fk_object = t.rowid 

		WHERE t.date_creation >  DATE_SUB(NOW(), INTERVAL 10 DAY) AND t.fk_statut = 2 AND targettype LIKE 'commande'";

		$final;
		$resultV = $this->db->query($sqlValide);
		$resultE = $this->db->query($sqlEnCours);

		
		$final->valide = $resultV;
		$final->EnCours = $resultE;

		return $final;

	}



	    /**
	 * List orders
	 *
	 * Get a list of orders
	 *
	 * @param string	       $sortfield	        Sort field
	 * @param string	       $sortorder	        Sort order
	 * @param int		       $limit		        Limit for list
	 * @param int		       $page		        Page number
	 * @param string   	       $thirdparty_ids	    Thirdparty ids to filter orders of (example '1' or '1,2,3') {@pattern /^[0-9,]*$/i}
	 * @param string           $sqlfilters          Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.date_creation:<:'20160101')"
     * @url     GET buyTheMost
	 * @return  array                               Array of order objects
	 *
	 * @throws RestException 404 Not found
	 * @throws RestException 503 Error
	 */
	public function buyTheMost($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 100, $page = 0, $thirdparty_ids = '', $sqlfilters = '')
	{
		global $db, $conf;



		if (!DolibarrApiAccess::$user->rights->commande->lire) {
			throw new RestException(401);
		}

		$sql = "SELECT p.rowid, p.label, p.ref, p.fk_product_type as type, p.tobuy, p.tosell, p.tobatch, p.barcode, SUM(pd.qty) as c";
		$textforqty = 'Qty';

		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseurdet as pd";
		$sql .= ", ".MAIN_DB_PREFIX."product as p";
		$sql .= ' WHERE p.entity IN ('.getEntity('product').')';
		$sql .= " AND p.rowid = pd.fk_product";
		if ($type !== '') {
			$sql .= " AND fk_product_type = ".((int) $type);
		}
		$sql .= " GROUP BY p.rowid, p.label, p.ref, p.fk_product_type, p.tobuy, p.tosell, p.tobatch, p.barcode LIMIT 100";

		$result = $db->query($sql);

   		 // Process and return the results
    	$response = array();

		if ($result->num_rows > 0) {
			while ($row = $db->fetch_array($result)) {
				$product_id = $row['rowid'];
				$product_label = $row['label'];
				$product_description = $row['description'];


				$productobj = new Product($db);
				$productobj->fetch($product_id);

				/* get images */

				// $dir = $conf->product->multidir_output[$productobj->entity];
				// if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
				// 	$dir .= '/'.get_exdir($productobj->id, 2, 0, 0, $productobj, 'product').$productobj->id."/photos/";
				// } else {
				// 	$dir .= '/'.get_exdir(0, 0, 0, 0, $productobj, 'product');
				// }

				


				// Prepare and execute the SQL query to retrieve photos from r2aw_ecm_files
				$photoSql = "SELECT filepath, filename, share
				FROM " . MAIN_DB_PREFIX . "ecm_files
				WHERE src_object_type = 'product'
				AND src_object_id = " . $product_id . "
				AND share != ''
				AND gen_or_uploaded = 'uploaded'
				AND (filename LIKE '%.png' OR filename LIKE '%.jpg' OR filename LIKE '%.jpeg' OR filename LIKE '%.gif' OR filename LIKE '%.bmp')";


				$photoResult = $db->query($photoSql);


				while ($photoRow = $db->fetch_array($photoResult)) {
					$filepath = $photoRow['filepath'];
					$filename = $photoRow['filename'];

					$filepath = str_replace('product', '', $filepath);
					$filepath = str_replace('produit', '', $filepath);

					

					// Construct the image URL
					$imgurl = DOL_MAIN_URL_ROOT . '/viewimage.php?modulepart=produit&entity=' . $productobj->entity . '&file=' . urlencode($filepath . '/' . $filename).'&hashp='.$photoRow['share'];

					

					// Retrieve and encode the image
				//    $imageData = file_get_contents($imgurl);
				//    $base64Image = base64_encode($imageData);

					// Add base64 encoded image to the array
					$productimages = array(
						'photo_name' => $filename,
						'photourl' => $imgurl,
					//    'photo' => $base64Image,
					);
				}



				/* end get images */

				// Add product details to the response
				$productData = array(
					'product_id' => $product_id,
					'product_label' => $product_label,
					'product_description' => $category_description,
					'product_image' => $productimages,
					// 'product_object' => $this->_cleanObjectDatas($productobj),
				);

				// Append productData to the response array
				$response[] = $productData;
			}
		}

		return $response;

	}

	

}
