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
 * @package    Ramp_Controller
 * @copyright  Copyright (c) 2012 Alyce Brady (http://www.cs.kzoo.edu/~abrady)
 * @license    http://www.cs.kzoo.edu/ramp/LICENSE.txt   Simplified BSD License
 *
 */

class IndexController extends Zend_Controller_Action
{
    const SETTING_PARAM = Ramp_Controller_KeyParameters::SETTING_PARAM;
    const ACT_PARAM = Ramp_Controller_KeyParameters::ACT_KEY_PARAM;

    // Define instance variables that hold basic configuration variables.
    protected $_configInfo;
    protected $_initialActivity = null;

    public function init()
    {
        /* Initialize action controller here */

        // Get various application variables from the Registry.
        $this->_configInfo = Ramp_RegistryFacade::getInstance();

        // Get the initial activity.
        $auth = Zend_Auth::getInstance();
        if ( $auth->hasIdentity() && is_object($auth->getIdentity()) )
        {
            // Authenticated user; use stored initial activity.
            $user = $auth->getIdentity();
            $this->_initialActivity = $user->initialActivity;
        }
        else
        {
            // Not an authenticated user; use the default initial activity.
            $this->_initialActivity =
                    $this->_configInfo->getDefaultInitialActivity();
        }

// $this->_getAuthDebuggingInfo();

    }

    public function indexAction()
    {
        // Redirect to the appropriate initial activity for this 
        // application and environment, if one has been specified
        // (see configs/application.ini).  Otherwise ask the user to 
        // choose an initial activity.
        if ( $this->_initialActivity != null )
        {
            $activityListName = urlencode($this->_initialActivity);
            $params = array(self::ACT_PARAM => $activityListName);

            $this->_helper->redirector('index', 'activity', null, $params);
        }
        else
        {
            $this->_forward('choose-activity-list');
        }
    }

    /**
     * Show the user a form from which they should choose a
     * table/table setting.
     *
     * NOTE: This function has not been used or tested for a long time.
     * DEPRECATED?  Certainly no longer used as initial page, as it was 
     * in the very first version of RAMP.
     */
    public function chooseTableAction()
    {
        // Instantiate the form that asks the user which table setting to 
        // retrieve.
        $form = new Application_Form_TableChoice();
        $form->submit->setLabel('Retrieve');

        // Specify the view to render.
        $this->view->form = $form;

        if ($this->getRequest()->isPost())
        {
            // Process the filled-out form that has been posted:
            // if the changes are valid, view the appropriate table.
            $formData = $this->getRequest()->getPost();
            if ($form->isValid($formData))
            {
                $settingName = $form->getValue('settingName');
                $settingName = urlencode($settingName);
                $params = array(self::SETTING_PARAM => $settingName);

                $this->_helper->redirector('index', 'table', null, $params);
            }
            else
            {
                $form->populate($formData);
            }
        }
    }

    /**
     * Show the user a form from which they should choose an
     * activity to perform.
     *
     * NOTE: This function has not been used or tested for a long time.
     * DEPRECATED?
     */
    public function chooseActivityListAction()
    {
        // Instantiate the form that asks the user which activity list to 
        // retrieve.
        $form = new Application_Form_ActivityChoice();
        $form->submit->setLabel('Retrieve');

        // Specify the view to render.
        $this->view->form = $form;

        if ($this->getRequest()->isPost())
        {
            // Process the filled-out form that has been posted:
            // if the changes are valid, view the appropriate activity list.
            $formData = $this->getRequest()->getPost();
            if ($form->isValid($formData))
            {
                $activityListName = $form->getValue('activityName');
                $activityListName = urlencode($activityListName);
                $params = array(self::ACT_PARAM => $activityListName);

                $this->_helper->redirector('index', 'activity', null, $params);
            }
            else
            {
                $form->populate($formData);
            }
        }
    }

    protected function _getAuthDebuggingInfo()
    {
        $acl = new Ramp_Acl();
        $roles = $acl->getRoles();
        $resources = $acl->getResources();
        $rules = $acl->getRules();
        $this->view->authDebugging .= "<blockquote><b>Roles</b>: " .
            print_r($roles, true) . "</blockquote>";
        $this->view->authDebugging .= "<blockquote><b>Resources</b>: " .
            print_r($resources, true) . "</blockquote>";
        $this->view->authDebugging .= "<blockquote><b>Rules</b>: " .
            print_r($rules, true) . "</blockquote>";
        $this->view->authDebugging .= "<blockquote><b>Request</b>: " .
            print_r($this->getRequest()->getParams(), true) . "</blockquote>";
    }

}

