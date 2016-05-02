<?php
/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 5/2/16 3:49 PM
 * @file: ListMembers.php
 */
class Mailchimp_ListsMembers extends Mailchimp_Abstract
{
    /**
     * @var Mailchimp_ListsMembersActivity
     */
    public $memberActivity;
    /**
     * @var Mailchimp_ListsMembersGoals
     */
    public $memberGoal;
    /**
     * @var Mailchimp_ListsMembersNotes
     */
    public $memberNotes;
}