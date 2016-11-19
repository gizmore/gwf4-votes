<?php

final class Votes_AddPoll extends GWF_Method
{
	const SESS_OPTIONS = 'GWF_VM_OPT';

	public function getHTAccess()
	{
		return 'RewriteRule ^poll/add$ index.php?mo=Votes&me=AddPoll [QSA]'.PHP_EOL;
	}
	
	public function execute()
	{
		if (false !== Common::getPost('add_opt')) {
			return $this->onAddOption().$this->templateAddPoll();
		}
		if (false !== Common::getPost('rem_opts')) {
			return $this->onRemOptions().$this->templateAddPoll();
		}
		if (false !== Common::getPost('create')) {
			return $this->onAddPoll();
		}
		
		return $this->templateAddPoll();
	}
	
	public function getForm()
	{
		$data = array(
			'opt' => array(GWF_Form::VALIDATOR),
		);
		$buttons = array(
			'add_opt' => $this->module->lang('btn_add_opt'),
			'rem_opts' => $this->module->lang('btn_rem_opts'),
			'create' => $this->module->lang('btn_create'),
		);
		
		$data['title'] = array(GWF_Form::STRING, '', $this->module->lang('th_title'));
		$data['reverse'] = array(GWF_Form::CHECKBOX, true, $this->module->lang('th_reverse'));
		$data['multi'] = array(GWF_Form::CHECKBOX, false, $this->module->lang('th_multi'));
		$data['guests'] = array(GWF_Form::CHECKBOX, false, $this->module->lang('th_guests'));
		if (Module_Votes::mayAddGlobalPoll(GWF_Session::getUser())) {
			$data['public'] = array(GWF_Form::CHECKBOX, false, $this->module->lang('th_vm_public'));
		}
		$data['view'] = array(GWF_Form::SELECT, GWF_VoteMulti::getViewSelect($this->module, 'view', intval(Common::getPost('view', GWF_VoteMulti::SHOW_RESULT_VOTED))), $this->module->lang('th_mvview'));
		$data['gid'] = array(GWF_Form::SELECT, GWF_GroupSelect::single('gid', Common::getPostString('gid', '0')), $this->module->lang('th_vm_gid'));
		$data['level'] = array(GWF_Form::INT, '0', $this->module->lang('th_vm_level'));
		
		$i = 1;
		foreach (GWF_Session::getOrDefault(self::SESS_OPTIONS, array()) as $item)
		{
			$data['opt['.$i.']'] = array(GWF_Form::STRING, $item, $this->module->lang('th_option', array( $i)));
			$i++;
		}
		
		
		$data['cmds'] = array(GWF_Form::SUBMITS, $buttons);
		
		
		return new GWF_Form($this, $data);
	}
	
	public function templateAddPoll()
	{
		$form = $this->getForm();
		$tVars = array(
			'form' => $form->templateY($this->module->lang('ft_create')),
		);
		return $this->module->template('add_poll.tpl', $tVars);
	}
	
	public function onAddOption($add_new=true)
	{
		$options = GWF_Session::getOrDefault(self::SESS_OPTIONS, array());
		$posted = Common::getPostArray('opt', array());
		$i = 0;
		foreach ($options as $i => $option)
		{
//			$i = $i+1;
			$options[$i] = isset($posted[$i]) ? $posted[$i] : '';
		}
		
		if ($add_new === true)
		{
			$i++;# = (string)($i+1);
			$options[$i] = '';
		}

		GWF_Session::set(self::SESS_OPTIONS, $options);
		
		return '';
	}
	
	public function onRemOptions()
	{
		GWF_Session::set(self::SESS_OPTIONS, array());
		return '';
	}
	
//	public function onCreate()
//	{
//		$form = $this->getForm();
//		if (false !== ($errors = $form->validate($this->module))) {
//			return $errors.$this->templateCreate();
//		}
//		
//		$opts = Common::getPost('opt', array());
//		
//		if (count($opts) < 1) {
//			return $this->module->error('err_no_options');
//		}
//		
//		foreach ($opts as $i => $opt)
//		{
//			$option = new GWF_VoteMultiOpt(array(
//				'vmo_id' => 0,
//				'vmo_vmid' => $vmid,
//				'vmo_text' => $opt,
//				'vmo_value' => $i,
//				'vmo_avg' => array(GDO::INT, GDO::NOT_NULL),
//				'vmo_votes' => array(GDO::UINT, 0),
//			));
//		}
//		
//		return $this->module->message('msg_mvote_added');
//	}
	
	##################
	### Validators ###
	##################
	public function validate_title(Module_Votes $m, $arg) { return GWF_Validator::validateString($m, 'title', $arg, $m->cfgMinTitleLen(), $m->cfgMaxTitleLen(), false); }

	private $checked_opt = false;
	public function validate_opt(Module_Votes $m, $arg)
	{
		if ($this->checked_opt) {
			return false;
		}
		$this->checked_opt = true;
		$opts = Common::getPostArray('opt', array());
		$post = array();
		$min = $this->module->cfgMinOptionLen();
		$max = $this->module->cfgMaxOptionLen();
		
		$err = '';
		foreach ($opts as $i => $op)
		{
			$op = trim($op);
			
			$i = (int)$i;
			
//			# XSS/SQLI escape! 
//			if (!is_numeric($i)) { $i = GWF_HTML::display($i); }
			
			$len = GWF_String::strlen($op);
			if ($len < $min || $len > $max)
			{
				$err .= ', '.$i;
			}
			
			$post[$i] = $op;
//			$_POST['opt'][$i] = $op;
		}
		
		$_POST['opt'] = $post;
		
		$this->onAddOption(false);
		
		if ($err === '') {
			return false;
		}
		return $this->module->lang('err_options', array(substr($err, 2), $min, $max));
	}
	
	public function validate_view(Module_Votes $m, $arg) { return GWF_VoteMulti::isValidViewFlag(($arg)) ? false : $m->lang('err_multiview'); }
	public function validate_gid(Module_Votes $m, $arg) { return GWF_Validator::validateGroupID($m, 'gid', $arg, false, true); }
	public function validate_level(Module_Votes $m, $arg) { return GWF_Validator::validateInt($m, 'level', $arg, 0, PHP_INT_MAX, '0'); }
	
	private function onAddPoll()
	{
		$form = $this->getForm();
		if (false !== ($errors = $form->validate($this->module)))
		{
			return $errors.$this->templateAddPoll();
		}
		
		$opts = Common::getPostArray('opt', array());
		if (count($opts) === 0)
		{
			return $this->module->error('err_no_options').$this->templateAddPoll();
		}
		
		$user = GWF_Session::getUser();
		$name = GWF_VoteMulti::createPollName(GWF_Session::getUser());
		$title = $form->getVar('title');
		$gid = $form->getVar('gid');
		$level = $form->getVar('level');
		$reverse = isset($_POST['reverse']);
		$is_multi = isset($_POST['multi']);
		$guest_votes = isset($_POST['guests']);
		$is_public = isset($_POST['public']);
		$result = (int)$form->getVar('view');
		if ($is_public && !$this->module->mayAddGlobalPoll($user))
		{
			return $this->module->error('err_global_poll').$this->templateAddPoll();
		}
		
		GWF_Session::remove(self::SESS_OPTIONS);
		
		return Module_Votes::installPollTable($user, $name, $title, $opts, $gid, $level, $is_multi, $guest_votes, $is_public, $result, $reverse);
	}
	
}

?>
