<?php
/**
 * @version 1.5 stable $Id: fields.php 1640 2013-02-28 14:45:19Z ggppdk $
 * @package Joomla
 * @subpackage FLEXIcontent
 * @copyright (C) 2009 Emmanuel Danan - www.vistamedia.fr
 * @license GNU/GPL v2
 * 
 * FLEXIcontent is a derivative work of the excellent QuickFAQ component
 * @copyright (C) 2008 Christoph Lukes
 * see www.schlu.net for more information
 *
 * FLEXIcontent is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

defined( '_JEXEC' ) or die( 'Restricted access' );

use Joomla\String\StringHelper;

// Register autoloader for parent controller, in case controller is executed by another component
JLoader::register('FlexicontentController', JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'controller.php');

// Manually import in case used by frontend, then model will not be autoloaded correctly via getModel('name')
require_once(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'models'.DS.'field.php');

/**
 * FLEXIcontent Component Fields Controller
 *
 * @package Joomla
 * @subpackage FLEXIcontent
 * @since 1.0
 */
class FlexicontentControllerFields extends FlexicontentController
{
	var $records_dbtbl  = 'flexicontent_fields';
	var $records_jtable = 'flexicontent_fields';
	var $record_name = 'field';
	var $record_name_pl = 'fields';
	var $_NAME = 'FIELD';

	var $runMode = 'standalone';

	var $exitHttpHead = null;
	var $exitMessages = array();
	var $exitLogTexts = array();
	var $exitSuccess  = true;

	/**
	 * Constructor
	 *
	 * @since 1.0
	 */
	function __construct()
	{
		parent::__construct();

		// Register task aliases
		$this->registerTask( 'add',          'edit' );
		$this->registerTask( 'apply',        'save' );
		$this->registerTask( 'apply_ajax',   'save' );
		$this->registerTask( 'save2new',     'save' );
		$this->registerTask( 'save2copy',    'save' );
		$this->registerTask( 'copy',         'copy' );
		$this->registerTask( 'copy_wvalues', 'copy' );

		$this->registerTask( 'exportxml', 'export' );
		$this->registerTask( 'exportsql', 'export' );
		$this->registerTask( 'exportcsv', 'export' );

		$this->option = $this->input->get('option', '', 'cmd');
		$this->task   = $this->input->get('task', '', 'cmd');
		$this->view   = $this->input->get('view', '', 'cmd');
		$this->format = $this->input->get('format', '', 'cmd');

		// Get return URL
		$this->returnURL = $this->input->get('return-url', null, 'base64');
		$this->returnURL = $this->returnURL ? base64_decode($this->returnURL) : $this->returnURL;

		// Check return URL if empty or not safe and set a default one
		if ( ! $this->returnURL || ! flexicontent_html::is_safe_url($this->returnURL) )
		{
			if ($this->view == $this->record_name)
			{
				$this->returnURL = 'index.php?option=com_flexicontent&view=' . $this->record_name_pl;
			}
			else if ( !empty($_SERVER['HTTP_REFERER']) && flexicontent_html::is_safe_url($_SERVER['HTTP_REFERER']) )
			{
				$this->returnURL = $_SERVER['HTTP_REFERER'];
			}
			else
			{
				$this->returnURL = null;
			}
		}

		// Can manage ACL
		$this->canManage = FlexicontentHelperPerm::getPerm()->CanFields;
	}


	/**
	 * Logic to save a record
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function save()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		$app   = JFactory::getApplication();
		$user  = JFactory::getUser();

		// Retrieve form data these are subject to basic filtering
		$data  = $this->input->get('jform', array(), 'array');  // Unfiltered data, validation will follow via jform

		// Set into model: id (needed for loading correct item), and type id (e.g. needed for getting correct type parameters for new items)
		$data['id'] = (int) $data['id'];
		$isnew = $data['id'] == 0;

		// Extra steps before creating the model
		if ($isnew)
		{
			// Nothing needed
		}

		// Get the model
		$model = $this->getModel($this->record_name);
		$model->setId($data['id']);  // Make sure id is correct
		$record = $model->getItem();

		// The save2copy task needs to be handled slightly differently.
		if ($this->task == 'save2copy')
		{
			// Check-in the original row.
			if ($model->checkin($data['id']) === false)
			{
				// Check-in failed
				$this->setError(JText::sprintf('JLIB_APPLICATION_ERROR_CHECKIN_FAILED', $model->getError()));
				$this->setMessage($this->getError(), 'error');

				// For errors, we redirect back to refer
				$this->setRedirect( $_SERVER['HTTP_REFERER'] );

				return false;
			}

			// Reset the ID, the multilingual associations and then treat the request as for Apply.
			$isnew = 1;
			$data['id'] = 0;
			$data['associations'] = array();
			$this->task = 'apply';

			// Keep existing model data (only clear ID)
			$model->set('id', 0);
			$model->setProperty('_id', 0);
		}

		// Calculate access
		$is_authorised = $model->canEdit($record);

		// Check access
		if ( !$is_authorised )
		{
			$app->enqueueMessage(JText::_('FLEXI_ALERTNOTAUTH_TASK'), 'error');
			$app->setHeader('status', 403, true);
			$this->setRedirect($this->returnURL);

			if ($this->input->get('fc_doajax_submit'))
				jexit(flexicontent_html::get_system_messages_html());
			else
				return false;
		}

		// Validate Form data
		$form = $model->getForm($data, false);
		$validated_data = $model->validate($form, $data);

		// Check for validation error
		if (!$validated_data)
		{
			// Get the validation messages and push up to three validation messages out to the user
			$errors	= $form->getErrors();
			for ($i = 0, $n = count($errors); $i < $n && $i < 3; $i++)
			{
				$app->enqueueMessage($errors[$i] instanceof Exception ? $errors[$i]->getMessage() : $errors[$i], 'error');
			}

			// Set the POSTed form data into the session, so that they get reloaded
			$app->setUserState($form->option.'.edit.'.$form->context.'.data', $data);      // Save the jform data in the session

			// For errors, we redirect back to refer
			$this->setRedirect( $_SERVER['HTTP_REFERER'] );

			if ($this->input->get('fc_doajax_submit'))
				jexit(flexicontent_html::get_system_messages_html());
			else
				return false;
		}

		// Extra custom step before model store
		if ($this->_beforeModelStore($validated_data, $data) === false)
		{
			$app->enqueueMessage($this->getError(), 'error');
			$app->setHeader('status', 500, true);

			if ($this->input->get('fc_doajax_submit'))
			{
				jexit(flexicontent_html::get_system_messages_html());
			}

			// For errors, we redirect back to refer
			$this->setRedirect( $_SERVER['HTTP_REFERER'] );
			return false;
		}

		if ( !$model->store($validated_data) )
		{
			$app->enqueueMessage($model->getError() ?: JText::_( 'FLEXI_ERROR_SAVING_'. $this->_NAME ), 'error');
			$app->setHeader('status', 500, true);

			if ($this->input->get('fc_doajax_submit'))
			{
				jexit(flexicontent_html::get_system_messages_html());
			}

			// For errors, we redirect back to refer
			$this->setRedirect( $_SERVER['HTTP_REFERER'] );
			return false;
		}

		// Clear dependent cache data
		$this->_clearCache();

		// Checkin the record
		$model->checkin();

		switch ($this->task)
		{
			case 'apply' :
				$link = 'index.php?option=com_flexicontent&view=' . $this->record_name . '&id='.(int) $model->get('id');
				break;

			case 'save2new' :
				$link = 'index.php?option=com_flexicontent&view=' . $this->record_name;
				break;

			default :
				$link = $this->returnURL;
				break;
		}
		$msg = JText::_( 'FLEXI_'. $this->_NAME .'_SAVED' );

		$app->enqueueMessage($msg, 'message');
		$this->setRedirect($link);

		if ($this->input->get('fc_doajax_submit'))
		{
			jexit(flexicontent_html::get_system_messages_html());
		}
	}


	/**
	 * Check in a record
	 *
	 * @since	1.5
	 */
	function checkin()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		$redirect_url = $this->returnURL;
		flexicontent_db::checkin($this->records_jtable, $redirect_url, $this);
	}


	/**
	 * Logic to publish records
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function publish()
	{
		$app   = JFactory::getApplication();
		$user  = JFactory::getUser();
		$model = $this->getModel($this->record_name_pl);

		$cid = $this->input->get('cid', array(), 'array');
		JArrayHelper::toInteger($cid);

		if (!is_array( $cid ) || count( $cid ) < 1)
		{
			$app->enqueueMessage(JText::_('FLEXI_SELECT_ITEM_PUBLISH'), 'error');
			$app->setHeader('status', 500, true);
			$this->setRedirect($this->returnURL);
			return;
		}

		// Calculate access
		$cid_noauth = array();
		$is_authorised = $this->canManage;
		if ( $is_authorised )
		{
			foreach($cid as $i => $_id)
			{
				if (!$user->authorise('flexicontent.publishfield', 'com_flexicontent.field.' . $_id))
				{
					$cid_noauth[] = $_id;
					unset($cid[$i]);
				}
			}
			$is_authorised = count($cid);
		}

		// Check access
		if ( !$is_authorised )
		{
			$app->enqueueMessage(JText::_('FLEXI_ALERTNOTAUTH_TASK'), 'error');
			$app->setHeader('status', 403, true);
			$this->setRedirect($this->returnURL);
			return;
		}
		else if (count($cid_noauth))
		{
			$app->enqueueMessage("You cannot change state of records : ", implode(', ', $cid_noauth), 'warning');
		}

		// Publish the record(s)
		$msg = '';
		if (!$model->publish($cid, 1))
		{
			$msg = JText::_( 'FLEXI_OPERATION_FAILED' ).' : '.$model->getError();
			throw new Exception($msg, 500);
		}

		$total = count($cid);
		$msg = $total . ' ' . JText::_( 'FLEXI_' . $this->_NAME . '_PUBLISHED' );

		// Clear dependent cache data
		$this->_clearCache();
		
		$this->setRedirect($this->returnURL, $msg);
	}
	
	
	/**
	 * Logic to unpublish records
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function unpublish()
	{
		$app   = JFactory::getApplication();
		$user  = JFactory::getUser();
		$model = $this->getModel($this->record_name_pl);

		$cid = $this->input->get('cid', array(), 'array');
		JArrayHelper::toInteger($cid);

		if (!is_array( $cid ) || count( $cid ) < 1)
		{
			$app->enqueueMessage(JText::_('FLEXI_SELECT_ITEM_UNPUBLISH'), 'error');
			$app->setHeader('status', 500, true);
			$this->setRedirect($this->returnURL);
			return;
		}

		// Calculate access
		$cid_noauth = array();
		$cid_locked = array();
		$model->canunpublish($cid, $cid_noauth, $cid_locked);
		$cid = array_diff($cid, $cid_noauth, $cid_locked);
		$is_authorised = count($cid);

		// Check access
		if ( !$is_authorised )
		{
			count($cid_locked)
				? $app->enqueueMessage(JText::_('FLEXI_YOU_CANNOT_UNPUBLISH_THESE_'. $this->_NAME .'S'), 'error')
				: $app->enqueueMessage(JText::_('FLEXI_ALERTNOTAUTH_TASK'), 'error');
			$app->setHeader('status', 403, true);
			$this->setRedirect($this->returnURL);
			return;
		}

		count($cid_locked)
			? $app->enqueueMessage(JText::sprintf('FLEXI_SKIPPED_RECORDS_BEING_OF_CORE_TYPE', count($cid_locked), JText::_('FLEXI_'. $this->_NAME .'S')) . '<br/>', 'warning')
			: false;
		count($cid_noauth)
			? $app->enqueueMessage(JText::sprintf('FLEXI_SKIPPED_RECORDS_NOT_AUTHORISED', count($cid_noauth), JText::_('FLEXI_'. $this->_NAME .'S')) . '<br/>', 'warning')
			: false;

		// Unpublish the record(s)
		$msg = '';
		if (!$model->publish($cid, 0))
		{
			$msg = JText::_( 'FLEXI_OPERATION_FAILED' ).' : '.$model->getError();
			throw new Exception($msg, 500);
		}

		$total = count($cid);
		$msg = $total . ' ' . JText::_( 'FLEXI_' . $this->_NAME . '_UNPUBLISHED' );

		// Clear dependent cache data
		$this->_clearCache();
		
		$this->setRedirect($this->returnURL, $msg);
	}


	/**
	 * Logic to delete records
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function remove()
	{
		$app   = JFactory::getApplication();
		$user  = JFactory::getUser();
		$model = $this->getModel($this->record_name_pl);

		$cid = $this->input->get('cid', array(), 'array');
		JArrayHelper::toInteger($cid);

		if (!is_array( $cid ) || count( $cid ) < 1)
		{
			$app->enqueueMessage(JText::_('FLEXI_SELECT_ITEM_DELETE'), 'error');
			$app->setHeader('status', 500, true);
			$this->setRedirect($this->returnURL);
			return;
		}

		// Calculate access
		$cid_noauth = array();
		$cid_locked = array();
		$model->candelete($cid, $cid_noauth, $cid_locked);
		$cid = array_diff($cid, $cid_noauth, $cid_locked);
		$is_authorised = count($cid);

		// Check access
		if ( !$is_authorised )
		{
			count($cid_locked)
				? $app->enqueueMessage(JText::_('FLEXI_YOU_CANNOT_REMOVE_CORE_'. $this->_NAME .'S'), 'error')
				: $app->enqueueMessage(JText::_('FLEXI_ALERTNOTAUTH_TASK'), 'error');
			$app->setHeader('status', 403, true);
			$this->setRedirect($this->returnURL);
			return;
		}

		count($cid_locked)
			? $app->enqueueMessage(JText::sprintf('FLEXI_SKIPPED_RECORDS_BEING_OF_CORE_TYPE', count($cid_locked), JText::_('FLEXI_'. $this->_NAME .'S')) . '<br/>', 'warning')
			: false;
		count($cid_noauth)
			? $app->enqueueMessage(JText::sprintf('FLEXI_SKIPPED_RECORDS_NOT_AUTHORISED', count($cid_noauth), JText::_('FLEXI_'. $this->_NAME .'S')) . '<br/>', 'warning')
			: false;

		// Delete the record(s)
		$msg = '';
		if (!$model->delete($cid))
		{
			$msg = JText::_( 'FLEXI_OPERATION_FAILED' ).' : '.$model->getError();
			throw new Exception($msg, 500);
		}

		$total = count( $cid );
		$msg = $total . ' ' . JText::_( 'FLEXI_'. $this->_NAME .'S_DELETED' );

		// Clear dependent cache data
		$this->_clearCache();

		$this->setRedirect($this->returnURL, $msg);
	}


	/**
	 * logic for cancel an action
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function cancel()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		$data = $this->input->get('jform', array(), 'array');  // Unfiltered data (no need for filtering)
		$this->input->set('cid', (int) $data['id']);

		$this->checkin();
	}


	/**
	 * Logic to create the view for record editing
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function edit()
	{
		$app      = JFactory::getApplication();
		$user     = JFactory::getUser();
		$session  = JFactory::getSession();
		$document = JFactory::getDocument();

		$this->input->set('view', $this->record_name);
		$this->input->set('hidemainmenu', 1);

		// Get/Create the view
		$viewType   = $document->getType();
		$viewName   = $this->input->get('view', $this->default_view, 'cmd');
		$viewLayout = $this->input->get('layout', 'default', 'string');
		$view = $this->getView($viewName, $viewType, '', array('base_path' => $this->basePath, 'layout' => $viewLayout));

		// Get/Create the model
		$model  = $this->getModel($this->record_name);
		$record = $model->getItem();

		// Push the model into the view (as default), later we will call the view display method instead of calling parent's display task, because it will create a 2nd model instance !!
		$view->setModel($model, true);
		$view->document = $document;

		// Calculate access
		$is_authorised = $model->canEdit($record);

		// Check access
		if ( !$is_authorised )
		{
			$app->setHeader('status', '403 Forbidden', true);
			$this->setRedirect($this->returnURL, JText::_('FLEXI_ALERTNOTAUTH_TASK'), 'error');
			return;
		}

		// Check if record is checked out by other editor
		if ( $model->isCheckedOut($user->get('id')) )
		{
			$app->setHeader('status', '400 Bad Request', true);
			$this->setRedirect($this->returnURL, JText::_('FLEXI_EDITED_BY_ANOTHER_ADMIN'), 'warning');
			return;
		}
		
		// Checkout the record and proceed to edit form
		if ( !$model->checkout() )
		{
			$app->setHeader('status', '400 Bad Request', true);
			$this->setRedirect($this->returnURL, JText::_('FLEXI_OPERATION_FAILED') . ' : ' . $model->getError(), 'error');
			return;
		}

		// Call display method of the view, instead of calling parent's display task, because it will create a 2nd model instance !!
		$view->display();
	}


	/**
	 * Method for clearing cache of data depending on records type
	 *
	 * return: string
	 * 
	 * @since 1.5
	 */
	private function _clearCache()
	{
		if ($this->input->get('task', '', 'cmd') == __FUNCTION__) die(__FUNCTION__ . ' : direct call not allowed');

		$cache = JFactory::getCache('com_flexicontent');
		$cache->clean();
		$itemcache = JFactory::getCache('com_flexicontent_items');
		$itemcache->clean();
		$filtercache = JFactory::getCache('com_flexicontent_filters');
		$filtercache->clean();
	}


	/**
	 * Method for doing some record type specific work before calling model store
	 *
	 * return: string
	 * 
	 * @since 1.5
	 */
	private function _beforeModelStore(& $validated_data, & $data)
	{
		if ($this->input->get('task', '', 'cmd') == __FUNCTION__) die(__FUNCTION__ . ' : direct call not allowed');

		if (!$validated_data['id'] && $validated_data['iscore'])
		{
			$this->setError('Field\'s "iscore" property is ON, but creating new fields as CORE is not allowed');
			return false;
		}
	}


	/**
	 * Logic to order up/down a field
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function reorder($dir=null)
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		
		// Get variables: model, user, field id, new ordering
		$model = $this->getModel('fields');
		$user  = JFactory::getUser();

		// Get ids of the fields
		$cid = $this->input->get('cid', array(0), 'array');

		// calculate access
		$is_authorised = $user->authorise('flexicontent.orderfields', 'com_flexicontent');

		// Check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH_TASK' ) );
		}
		else if ( $model->move($dir) )
		{
			// success
		}
		else
		{
			$msg = JText::_( 'FLEXI_ERROR_SAVING_ORDER' );
			JError::raiseWarning( 500, $model->getError() );
		}

		$this->setRedirect($this->returnURL);
	}


	/**
	 * Logic to orderup a field
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function orderup()
	{
		$this->reorder($dir=-1);
	}

	/**
	 * Logic to orderdown a field
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function orderdown()
	{
		$this->reorder($dir=1);
	}

	/**
	 * Logic to mass ordering fields
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function saveorder()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));
		
		// Get variables: model, user, field id, new ordering
		$model = $this->getModel('fields');
		$user  = JFactory::getUser();

		// Get ids of the fields
		$cid = $this->input->get('cid', array(0), 'array');
		$order = $this->input->get('order', array(0), 'array');

		// calculate access
		$is_authorised = $user->authorise('flexicontent.orderfields', 'com_flexicontent');
		
		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH_TASK' ) );
		}
		else if(!$model->saveorder($cid, $order))
		{
			$msg = JText::_( 'FLEXI_OPERATION_FAILED' );
			JError::raiseWarning( 500, $model->getError() );
		}
		else
		{
			$msg = JText::_( 'FLEXI_NEW_ORDERING_SAVED' );
		}

		$this->setRedirect($this->returnURL, $msg);
	}

	/**
	 * Logic to set the access level of the Fields
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function access()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		$user  = JFactory::getUser();
		$model = $this->getModel('fields');

		$cid = $this->input->get('cid', array(0), 'array');
		$field_id = (int) $cid[0];
		$task  = $this->input->get('task', '', 'cmd');
		
		// calculate access
		$asset = 'com_flexicontent.field.' . $field_id;
		$is_authorised = $user->authorise('flexicontent.publishfield', $asset);
		
		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH_TASK' ) );
			$this->setRedirect($this->returnURL);
			return;
		}
		
		$accesses	= JRequest::getVar( 'access', array(0), 'post', 'array' );
		$access = $accesses[$field_id];
		
		if (!$model->saveaccess( $field_id, $access ))
		{
			$msg = JText::_( 'FLEXI_OPERATION_FAILED' );
			JError::raiseWarning( 500, $model->getError() );
		}
		else
		{
			$msg = '';
			$cache = JFactory::getCache('com_flexicontent');
			$cache->clean();
		}
		
		$this->setRedirect($this->returnURL, $msg);
	}


	/**
	 * Logic to copy the fields
	 *
	 * @access public
	 * @return void
	 * @since 1.5
	 */
	function copy()
	{
		// Check for request forgeries
		JSession::checkToken('request') or jexit(JText::_('JINVALID_TOKEN'));

		// Initialize variables
		$app = JFactory::getApplication();
		$user  = JFactory::getUser();
		$task   = $this->input->get('task', 'copy', 'cmd');
		$option = $this->input->get('option', '', 'cmd');

		// Get model
		$model = $this->getModel('fields');

		// Get ids of the fields
		$cid = $this->input->get('cid', array(0), 'array');

		// calculate access
		$is_authorised = $user->authorise('flexicontent.copyfields', 'com_flexicontent');
		
		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH_TASK' ) );
			$this->setRedirect($this->returnURL);
			return;
		}
		
		// Remove core fields
		$cid_locked = array();
		$non_core_cid = array();
		
		// Copying of core fields is not allowed
		foreach ($cid as $id)
		{
			if ($id < 15) {
				$cid_locked[] = $id;
			} else {
				$non_core_cid[] = $id;
			}
		}
		
		// Remove uneditable fields
		$auth_cid = array();
		$non_auth_cid = array();
		
		// Cannot copy fields you cannot edit
		foreach ($non_core_cid as $id)
		{
			$asset = 'com_flexicontent.field.' . $id;
			$is_authorised = $user->authorise('flexicontent.editfield', $asset);
			
			if ($is_authorised) {
				$auth_cid[] = $id;
			} else {
				$non_auth_cid[] = $id;
			}
		}
		
		// Try to copy fields
		$ids_map = $model->copy( $auth_cid, $task == 'copy_wvalues');
		if ( !$ids_map ) {
			$msg = JText::_( 'FLEXI_FIELDS_COPY_FAILED' );
			JError::raiseWarning( 500, $model->getError() );
		} else {
			$msg = '';
			if (count($ids_map)) {
				$msg .= JText::sprintf('FLEXI_FIELDS_COPY_SUCCESS', count($ids_map)) . ' ';
			}
			if ( count($auth_cid)-count($ids_map) ) {
				//$msg .= JText::sprintf('FLEXI_FIELDS_SKIPPED_DURING_COPY', count($auth_cid)-count($ids_map)) . ' ';
			}
			if (count($cid_locked)) {
				$msg .= JText::sprintf('FLEXI_FIELDS_CORE_FIELDS_NOT_COPIED', count($cid_locked)) . ' ';
			}
			if (count($non_auth_cid)) {
				$msg .= JText::sprintf('FLEXI_FIELDS_UNEDITABLE_FIELDS_NOT_COPIED', count($non_auth_cid)) . ' ';
			}
			$cache = JFactory::getCache('com_flexicontent');
			$cache->clean();
		}
		
		
		$filter_type = $app->getUserStateFromRequest( $option.'.fields.filter_type', 'filter_type', '', 'int' );
		if ($filter_type)
		{
			$app->setUserState( $option.'.fields.filter_type', '' );
			$msg .= ' '.JText::_('FLEXI_TYPE_FILTER_CLEARED_TO_VIEW_NEW_FIELDS');
		}
		$this->setRedirect($this->returnURL, $msg);
	}


	/**
	 * Logic to toggle boolean property of fields
	 *
	 * @access public
	 * @return void
	 * @since 1.0
	 */
	function toggleprop()
	{
		$user  = JFactory::getUser();
		$model = $this->getModel('fields');

		$cid = $this->input->get('cid', array(), 'array');
		JArrayHelper::toInteger($cid);
		$propname = $this->input->get('propname', null, 'cmd');

		if (!is_array( $cid ) || count( $cid ) < 1)
		{
			JError::raiseWarning(500, JText::_( 'FLEXI_SELECT_ITEM_TOGGLE_PROPERTY' ));
			$this->setRedirect($this->returnURL);
			return;
		}

		// calculate access
		$cid_noauth = array();
		foreach($cid as $i => $_id)
		{
			if (!$user->authorise('flexicontent.publishfield', 'com_flexicontent.field.' . $_id))
			{
				$cid_noauth[] = $_id;
				unset($cid[$i]);
			}
		}
		$is_authorised = count($cid);

		// check access
		if ( !$is_authorised )
		{
			JError::raiseWarning( 403, JText::_( 'FLEXI_ALERTNOTAUTH_TASK' ) );
			$this->setRedirect($this->returnURL);
			return;
		}
		else if (count($cid_noauth))
		{
			$app->enqueueMessage("You cannot change state of fields : ", implode(', ', $cid_noauth));
		}


		$unsupported = 0;
		$locked = 0;
		$affected = $model->toggleprop($cid, $propname, $unsupported, $locked);
		if ($affected === false)
		{
			$msg = JText::_( 'FLEXI_OPERATION_FAILED' );
			JError::raiseWarning( 500, $model->getError() );
			$this->setRedirect($this->returnURL, $msg);
			return;
		}

		// A message about total count of affected rows , and about skipped fields (unsupported or locked)
		$prop_map = array(
			'issearch'=>'FLEXI_TOGGLE_TEXT_SEARCHABLE',
			'isfilter'=>'FLEXI_TOGGLE_FILTERABLE',
			'isadvsearch'=>'FLEXI_TOGGLE_ADV_TEXT_SEARCHABLE',
			'isadvfilter'=>'FLEXI_TOGGLE_ADV_FILTERABLE'
		);
		$property_fullname = isset($prop_map[$propname]) ? "'".JText::_($prop_map[$propname])."'" : '';

		$msg = JText::sprintf( 'FLEXI_FIELDS_TOGGLED_PROPERTY', $property_fullname, $affected);
		if ($affected < count($cid))
		{
			$msg .= '<br/>'.JText::sprintf( 'FLEXI_FIELDS_TOGGLED_PROPERTY_FIELDS_SKIPPED', $unsupported + $locked, $unsupported, $locked);
		}

		// Clean cache as needed
		$cache = JFactory::getCache('com_flexicontent');
		$cache->clean();
		$itemcache = JFactory::getCache('com_flexicontent_items');
		$itemcache->clean();

		$this->setRedirect($this->returnURL, $msg);
	}


	/**
	 * Task for AJAX request, for creating HTML for toggling search properties for many fields
	 *
	 * return: string
	 * 
	 * @since 1.5
	 */
	function selectsearchflag()
	{
		$btn_class = 'hasTooltip btn btn-small';
		
		$state['issearch'] = array( 'name' =>'FLEXI_TOGGLE_TEXT_SEARCHABLE', 'desc' =>'FLEXI_FIELD_CONTENT_LIST_TEXT_SEARCHABLE_DESC', 'icon' => 'search', 'btn_class' => 'btn-success', 'clear' => true );
		$state['isfilter'] = array( 'name' =>'FLEXI_TOGGLE_FILTERABLE', 'desc' =>'FLEXI_FIELD_CONTENT_LIST_FILTERABLE_DESC', 'icon' => 'filter', 'btn_class' => 'btn-success', 'clear' => true );
		$state['isadvsearch'] = array( 'name' =>'FLEXI_TOGGLE_ADV_TEXT_SEARCHABLE', 'desc' =>'FLEXI_FIELD_ADVANCED_TEXT_SEARCHABLE_DESC', 'icon' => 'search', 'btn_class' => 'btn-info', 'clear' => true );
		$state['isadvfilter'] = array( 'name' =>'FLEXI_TOGGLE_ADV_FILTERABLE', 'desc' =>'FLEXI_FIELD_ADVANCED_FILTERABLE_DESC', 'icon' => 'filter', 'btn_class' => 'btn-info', 'clear' => true );
		
?><div id="flexicontent" class="flexicontent" style="padding-top:5%;"><?php
		
		foreach($state as $shortname => $statedata) {
			$css = "width:216px; margin:0px 24px 12px 0px; text-align: left;";
			$link = JURI::base(true)."/index.php?option=com_flexicontent&task=fields.toggleprop&propname=".$shortname."&". JSession::getFormToken() ."=1";
			$icon = $statedata['icon'];
			
			if ($shortname=='issearch') echo '<br/><span class="label">'. JText::_( 'FLEXI_TOGGLE' ).'</span> '.JText::_( 'Content Lists' ).'<br/>';
			else if ($shortname=='isadvsearch') echo '<br/><span class="label">'. JText::_( 'FLEXI_TOGGLE' ).'</span> '.JText::_( 'Search View' ).'<br/>';
			?>
			<span style="<?php echo $css; ?>" class="<?php echo $btn_class.' '.$statedata['btn_class']; ?>" title="<?php echo JText::_( $statedata['desc'] ); ?>" data-placement="right"
				onclick="window.parent.document.adminForm.propname.value='<?php echo $shortname; ?>'; window.parent.document.adminForm.boxchecked.value==0  ?  alert('<?php echo JText::_('FLEXI_NO_ITEMS_SELECTED'); ?>')  :  window.parent.Joomla.submitbutton('fields.toggleprop')"
			>
				<span class="icon-<?php echo $icon; ?>"></span><?php echo JText::_( $statedata['name'] ); ?>
			</span>
			<?php
			if ( isset($statedata['clear']) ) echo '<div class="fcclear"></div>';
		}

?></div><?php

		return;
	}
}
