<?php
final class Votes_Like extends GWF_Method
{
	/**
	 * @var GWF_VoteLike
	 */
	private $votelike;
	private $score;
	private $user;
	
	public function getHTAccess()
	{
		return
			'RewriteRule ^like/([0-9]+)/?$ index.php?mo=Votes&me=Like&vlid=$1&score=1 [QSA]'.PHP_EOL.
			'RewriteRule ^dislike/([0-9]+)/?$ index.php?mo=Votes&me=Like&vlid=$1&score=0 [QSA]'.PHP_EOL;
	}

	public function execute()
	{
		if (false !== ($error = $this->sanitize()))
		{
			return $error;
		}

		$this->user = GWF_User::getStaticOrGuest();
		if (!$this->user->isUser())
		{
			return GWF_HTML::err('ERR_NO_PERMISSION');
		}
		
		return $this->onVote();
	}

	private function sanitize()
	{
		if (false === ($this->votelike = GWF_VoteLike::getByID(Common::getGet('vlid'))))
		{
			return $this->module->error('err_votescore');
		}

		if ($this->votelike->isIrreversible() && $this->votelike->hasVoted(GWF_Session::getUser()))
		{
			return $this->module->error('err_irreversible');
		}

		if (false === ($this->score = Common::getGet('score')))
		{
			return $this->module->error('err_score');
		}

		if (!$this->votelike->isInRange($this->score))
		{
			return $this->module->error('err_score');
		}

		if ($this->votelike->isExpired())
		{
			return $this->module->error('err_expired');
		}

		if ($this->votelike->isDisabled())
		{
			return $this->module->error('err_disabled');
		}

		return false;
	}

	private function onVote()
	{
		$user = $this->user;
		if (!$this->votelike->isGuestVote()) {
			return $this->module->error('err_no_guest');
		}

		$ip = GWF_IP6::getIP(GWF_IP_QUICK);
		if (false === ($vsr = GWF_VoteScoreRow::getByIP($this->votelike->getID(), $ip)))
		{
			if (false === $this->votelike->onGuestVote($this->score, $ip)) {
				return GWF_HTML::err('ERR_DATABASE', array( __FILE__, __LINE__));
			}
			return $this->onVoted(false);
		}
		else
		{
			if ($vsr->isUserVote()) {
				return $this->module->message('err_vote_ip');
			}
			if (!$vsr->isGuestVoteExpired($this->module->cfgGuestTimeout())) {
				$this->votelike->revertVote($vsr, $ip, 0);
			}
			if (false === $this->votelike->onGuestVote($this->score, $ip)) {
				return GWF_HTML::err('ERR_DATABASE', array( __FILE__, __LINE__));
			}
				
			return $this->onVoted(false);
		}
	}

	private function onVoted($user)
	{
		GWF_Hook::call(GWF_Hook::VOTED_SCORE, $user, array($this->votelike->getID(), $this->score));

		return isset($_GET['ajax']) ? $this->module->message('msg_voted_ajax') : $this->module->message('msg_voted', array(GWF_Session::getLastURL()));
	}

	private function onUserVote(GWF_User $user)
	{
		if (false !== ($err = $this->votelike->onUserVoteSafe($this->score, $user->getID()))) {
			return $err;
		}
		return $this->onVoted($user);
	}


}