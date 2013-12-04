<?php

/**
 * RAMP: Records and Activity Management Program
 *
 * LICENSE
 *
 * This source file is subject to the BSD-2-Clause license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.cs.kzoo.edu/ramp/LICENSE.txt
 *
 * @category   Ramp
 * @package    Ramp
 * @copyright  Copyright (c) 2013 Alyce Brady
 * @license    http://www.cs.kzoo.edu/ramp/LICENSE.txt   Simplified BSD License
 *
 * This class was added based on a tutorial found at
 * http://www.ens.ro/2012/03/20/zend-authentication-and-authorization-tutorial-with-zend_auth-and-zend_acl/
 * and on Zend Framework in Action by Allen, Lo, and Brown, 2009, pp. 140-146.
 *
 */
class Ramp_Acl extends Zend_Acl
{
    const DEFAULT_ROLE = 'guest';

    const DELIM = Ramp_Controller_KeyParameters::DELIM;
                                            // Separates resource sections

    const USERS_TABLE = 'ramp_auth_users';
    const AUTHORIZATIONS_TABLE = 'ramp_auth_auths';

    // ACL categories for Table actions.
    const VIEW = 'View';
    const ADD = 'AddRecords';
    const MOD = 'ModifyRecords';
    const DEL = 'DeleteRecords';
    const ALL = 'All';

    protected $_authInfo;
    protected $_tableCategories;
    protected $_tableAccessRules;

    // STATIC (CLASS) FUNCTIONS

    /**
     * Create an associative array of table action categories and the
     * list of actions associated with each category.
     * (Static (Class) function.)
     */
    public static function createCategoryConverter()
    {
	// Since the table access rules in the database are by
	// category rather than by individual actions, need to
        // create a "category converter" that associates categories
        // with lists of actions.  The 'index' action is part of the
        // VIEW category because it always forwards to either a search
        // or view action.
        $viewActions = array('index', 'search', 'list-view', 'table-view',
                             'record-view');
        $addActions = array('add');
        $modifyActions = array('record-edit');
        $deleteActions = array('delete');
        $categoryConverter[self::VIEW] = $viewActions;
        $categoryConverter[self::ADD] = $addActions;
        $categoryConverter[self::MOD] = $modifyActions;
        $categoryConverter[self::DEL] = $deleteActions;
        $categoryConverter[self::ALL] =
                            array_merge($viewActions, $addActions,
                                        $modifyActions, $deleteActions);
        return $categoryConverter;
    }

    /*
     * CONSTRUCTOR:
     *
     * Populates the Access Controll List with roles, resources, and 
     * access control rules.
     *
     * ROLES: Defines one default role (guest) internally and reads
     * additional roles from the Zend Registry.
     *
     * RESOURCES: Defines resources internally for most actions in the
     * Index, Auth, and Error Controllers.  Reads additional resources from
     * the Zend Registry and deduces others by scanning rules in the database.
     * (Only rules involving activity directories and table/report actions
     * may be specified in the database, so those are the only types of 
     * resources derived from it.)
     * Resources fall into three categories, specified as follows:
     *    Controller actions:    controller::action
     *    Activity directories:  activity::index::directory
     *    Table/Report actions:  table::action::tableName
     * Table and report resources are specific actions on specific 
     * tables (note: tables, not settings).
     *
     * RULES: Defines some basic access control list rules internally and
     * reads in additional rules from the Zend Registry and the database.
     * (Only rules involving activity directories and table/report actions
     * may be specified in the database.)  ACL rules consist of (role, 
     * resource) pairings establishing what resources the role is 
     * authorized to use.
     */
    public function __construct()
    {
        // Some authorization resources and rules come from the 
        // Authentication/Authorization database.
        $this->_authInfo = new Application_Model_DbTable_Auths();

	// Table access resources specify resources by categories of
	// actions rather than by individual actions.  Create a
	// "category converter" to use in converting categories to actions.
        $this->_tableCategories = self::createCategoryConverter();


        /* ADDING ROLES */

        // Add the built-in, default role called "guest".
        $this->addRole(new Zend_Acl_Role(self::DEFAULT_ROLE));

        // Add roles defined in the Registry.
        $registryFacade = Ramp_RegistryFacade::getInstance();
        $aclRoles = $registryFacade->getAclRoles();
        if ( ! empty($aclRoles) )
        {
            $this->_addRoles($aclRoles);
        }


        /* ADDING RESOURCES */    

        // Add the basic resources: actions from the Index, Auth, and Error 
        // controllers and the pre-defined, built-in Users and 
        // Authorizations tables.
        $this->_addBasicResources();

        // Add resources defined in the Registry.
        $aclResources = $registryFacade->getAclResources();
        if ( ! empty($aclResources) )
        {
            $this->_addResources($aclResources);
        }

        // Add resources derived from authorization rules in the database.
        $this->_addResources($this->_authInfo->getResources());


        /* ASSIGNING RESOURCES TO ROLES */ 

        // Identify minimal resources available to anyone (even guests).
        $this->_establishMinimalAuthorizations();

        // Add rules defined in the Registry.
        $aclRules = $registryFacade->getAclRules();
        foreach ( $aclRules as $rule )
        {
            $components = explode(self::DELIM, $rule);
            // There must be at least 2 components: role and resource.
            if ( count($components) >= 2 )
            {
                $role = array_shift($components);
                $resource = implode(self::DELIM, $components);
                $this->allow($role, $resource);
            }
        }

        // Add authorization rules in the database.
        $this->_addRules($this->_authInfo->getAccessRules());

    }

    /**
     * Determines whether the current user is authorized to access
     * the requested  resource.
     *
     * @param   $resource  the requested resource
     */
    public function authorizesCurrentUser($resource)
    {
        // If the default role allows access to the requested resource, 
        // that's good enough.
        if ( $this->isAllowed(self::DEFAULT_ROLE, $resource) )
        {
            return true;
        }

        // Otherwise, must be an authenticated user whose role allows access.
        $auth = Zend_Auth::getInstance();
        if ( $auth->hasIdentity() && is_object($auth->getIdentity()) )
        {
            $user = $auth->getIdentity();

            // Check the user role against the requested resource.
            if ( $this->hasRole($user->role) && $this->has($resource)
                   && $this->isAllowed($user->role, $resource) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * DEBUGGING: Gets all roles.
     */
    public function getRoles()
    {
        return array_keys($this->_getRoleRegistry()->getRoles());
    }

    /**
     * DEBUGGING: Gets all resources.
     */
    public function getResources()
    {
        return array_keys($this->_resources);
    }

    /**
     * DEBUGGING: Gets all rules.
     */
    public function getRules()
    {
        // Too hard to return all rules, so just concentrate on rules 
        // from Registry and Database.
        $configInfo = Ramp_RegistryFacade::getInstance();
        $regRules = $configInfo->getAclRules();
        $dbAuthInfo = new Application_Model_DbTable_Auths();
        $dbRules = $dbAuthInfo->getAccessRules();
        return array_merge($regRules, $dbRules);
        /*
            $simpleList = array();
            $byResourceId = $this->_rules['byResourceId'];
            foreach ( $byResourceId as $resource => $ruleInfo )
            {
                $simpleList['resource'] = "hi"; // $ruleInfo['byRuleId'];
            }
            return $simpleList;
         */
    }

    /**
     * Adds basic resources: actions from the Index, Auth, and Error
     * controllers and the pre-defined, built-in Users and Authorizations
     * tables.
     */
    protected function _addBasicResources()
    {
        //Note: Any action in a controller with multiple camel-cased words 
        //      (e.g., chooseActivityListAction in IndexController) is
        //      named as a resource like this: choose-activity-list.

        // INDEX CONTROLLER: all actions
        $this->add(new Zend_Acl_Resource('index::index'));
        $this->add(new Zend_Acl_Resource('index::choose-table'));
        $this->add(new Zend_Acl_Resource('index::choose-activity-list'));
        $this->add(new Zend_Acl_Resource('index::menu'));

        // AUTHORIZATION CONTROLLER: all actions
        $this->add(new Zend_Acl_Resource('auth::index'));
        $this->add(new Zend_Acl_Resource('auth::login'));
        $this->add(new Zend_Acl_Resource('auth::logout'));
        $this->add(new Zend_Acl_Resource('auth::unauthorized'));
        $this->add(new Zend_Acl_Resource('auth::init-password'));
        $this->add(new Zend_Acl_Resource('auth::change-password'));
        $this->add(new Zend_Acl_Resource('auth::reset-password'));
        $this->add(new Zend_Acl_Resource('auth::validate-roles'));
        $this->add(new Zend_Acl_Resource('auth::validate-acl-rules'));
        $this->add(new Zend_Acl_Resource('auth::view-acl-info'));

        // ERROR CONTROLLER: all actions
        $this->add(new Zend_Acl_Resource('error::error'));

        // DOCUMENT CONTROLLER: all actions
        $this->add(new Zend_Acl_Resource('document::index'));

        // BUILT-IN TABLES:
        // Get a list of all Table controller actions.
        $actions = self::createCategoryConverter();
        foreach ( $actions[self::ALL] as $action )
        {
            $resourceName = "table::$action::" . self::USERS_TABLE;
            $this->add(new Zend_Acl_Resource($resourceName));
            $resourceName = "table::$action::" . self::AUTHORIZATIONS_TABLE;
            $this->add(new Zend_Acl_Resource($resourceName));
        }

    }

    /**
     * Establishes minimal authorizations (resources available to anyone,
     * even guests who are not logged in).
     */
    protected function _establishMinimalAuthorizations()
    {
        $this->allow(self::DEFAULT_ROLE, 'error::error');

        $this->allow(self::DEFAULT_ROLE, 'index::menu');
        $this->allow(self::DEFAULT_ROLE, 'index::index');
        $this->allow(self::DEFAULT_ROLE, 'index::choose-table');
        $this->allow(self::DEFAULT_ROLE, 'index::choose-activity-list');

        $this->allow(self::DEFAULT_ROLE, 'auth::index');
        $this->allow(self::DEFAULT_ROLE, 'auth::login');
        $this->allow(self::DEFAULT_ROLE, 'auth::logout');
        $this->allow(self::DEFAULT_ROLE, 'auth::unauthorized');

        // All users should be able to set or change their password if 
        // Ramp is handling authentication internally.
        $registryFacade = Ramp_RegistryFacade::getInstance();
        if ( $registryFacade->usingInternalAuthentication() )
        {
            $this->allow(self::DEFAULT_ROLE, 'auth::init-password');
            $this->allow(self::DEFAULT_ROLE, 'auth::change-password');
        }
    }

    /**
     * Adds roles with parent information.
     * Based on Zend Framework in Action by Allen, Lo, and Brown, 2009, p. 142.
     *
     * @param   $roles   array of (role => parents) associations,
     *                   where the "parents" in each association can be
     *                   either a single parent or  an array of parents
     */
    protected function _addRoles($roles)
    {
        foreach ( $roles as $name => $parents )
        {
            if ( ! $this->hasRole($name) )
            {
                $parents = empty($parents) ? null : $parents;
                $this->addRole(new Zend_Acl_Role($name), $parents);
            }
        }
    }

    /**
     * Adds the specified resources.
     *
     * @param   $prefix     a prefix to put in front of all resources
     * @param   $resources  a single resource or an array of resources
     */
    protected function _addResources($resources, $prefix='')
    {
        if ( ! empty($resources) )
        {
            if ( is_array($resources) )
            {
                foreach ( $resources as $resource )
                {
                    $this->_addResource($prefix, $resource);
                }
            }
            else  // $resources is actually a single resource
            {
                $this->_addResource($prefix, $resources);
            }
        }
    }

    /**
     * Adds the specified resource.
     * 
     * @param   $prefix     a prefix to put in front of all resources
     * @param   $resource   a single resource
     */
    protected function _addResource($prefix, $resource)
    {
        $fullResource = $prefix . $resource;
        if ( ! $this->has($fullResource) )
        {
            $this->add(new Zend_Acl_Resource($fullResource));
        }
    }

    /**
     * Adds the specified rules.
     *
     * @param   $rules   array of (role => rule) associations
     */
    protected function _addRules($rules)
    {
        foreach ( $rules as $key => $rule )
        {
            foreach ( $rule as $role => $resource )
            {
                try {
                    $this->allow($role, $resource);
                } catch (Exception $e)  {  /* Ignore */  }
            }
        }
    }

}
