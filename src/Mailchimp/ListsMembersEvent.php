<?php
/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 5/2/16 4:31 PM
 * @file: ListsMembersActivity.php
 */
class Mailchimp_ListsMemberEvent extends Mailchimp_Abstract
{
    public function get($listId,$subscriberHash,$fields,$excludeFields)
    {
        $_params = array();
        if($fields) $_params['fields'] = $fields;
        if($excludeFields) $_params['exclude_fields'] = $excludeFields;

        return $this->master->call('lists/'.$listId.'/members/'.$subscriberHash.'/events',$_params,Mailchimp::GET);
    }
    public function add($listId,$memberHash,$name, $properties, $is_syncing, $ocurred_at)
    {
        $_params = array();
        if ($name) $_params['name'] = $name;
        if ($properties) $_params['properties'] = $properties;
        if ($is_syncing) $_params['is_syncing'] = $is_syncing;
        if ($ocurred_at) $_params['ocurred_at'] = $ocurred_at;
        return $this->master->call('lists/'.$listId.'/members/'.$memberHash.'/events',$_params,Mailchimp::POST);
    }
}