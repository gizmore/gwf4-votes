<?php
final class GWF_VoteLike extends GDO #implements GDO_Sortable, GDO_Editable
{
	#################
	### Constants ###
	#################
	const DATE_LEN = GWF_Date::LEN_SECOND;
	const ENABLED = 0x01;
	const GUEST_VOTES = 0x02;
	const SHOW_RESULT_ALWAYS = 0x10;
	const SHOW_RESULT_VOTED = 0x20;
	const SHOW_RESULT_NEVER = 0x40;
	const IRREVERSIBLE = 0x100;

	###########
	### GDO ###
	###########
	public function getClassName() { return __CLASS__; }
	public function getTableName() { return GWF_TABLE_PREFIX.'vote_like'; }
	public function getOptionsName() { return 'vs_options'; }
	public function getColumnDefines()
	{
		return array(
			'vl_id' => array(GDO::AUTO_INCREMENT),
			'vl_name' => array(GDO::VARCHAR|GDO::ASCII|GDO::CASE_S|GDO::UNIQUE, GDO::NOT_NULL, 63),
			'vl_date' => array(GDO::CHAR|GDO::ASCII|GDO::CASE_S, GDO::NOT_NULL, self::DATE_LEN),
			'vl_expire_date' => array(GDO::CHAR|GDO::ASCII|GDO::CASE_S, GDO::NULL, self::DATE_LEN),
			'vl_options' => array(GDO::UINT, 0),
				
			'vl_likes' => array(GDO::UINT, GDO::NOT_NULL),
			'vl_dislikes' => array(GDO::UINT, GDO::NOT_NULL),
		);
	}

// 	public function getEditHREF() { return GWF_WEB_ROOT.sprintf('index.php?mo=Votes&me=Staff&editvl=%s', $this->getID()); }
// 	public function getShowHREF() { return GWF_WEB_ROOT.sprintf('index.php?mo=Votes&me=Staff&showvl=%s', $this->getID()); }

	##################
	### Convinient ###
	##################
	public function getID() { return $this->getVar('vl_id'); }
	public function getSum() { return $this->getVar('vs_sum'); }
	public function getCount() { return $this->getLikes() + $this->getDislikes(); }
	public function getLikes() { return $this->getVar('vl_likes'); }
	public function getDislikes() { return $this->getVar('vl_dislikes'); }
	public function isGuestVote() { return $this->isOptionEnabled(self::GUEST_VOTES); }
	public function isDisabled() { return !$this->isOptionEnabled(self::ENABLED); }
	public function isIrreversible() { return $this->isOptionEnabled(self::IRREVERSIBLE); }
	public function isExpired()
	{
		if ('' === ($vsed = $this->getVar('vl_expire_date')))
		{
			return false;
		}
		return $vsed < GWF_Time::getDate(self::DATE_LEN);
	}

	######################
	### Static Getters ###
	######################
	/**
	* Get a votescore by id.
	* @param int $votescore_id
	* @return GWF_VoteScore
	*/
	public static function getByID($votescore_id)
	{
		return self::table(__CLASS__)->getRow($votescore_id);
	}

	/**
	 * Get a votescore by name.
	 * @param string $name
	 * @return GWF_VoteScore
	 */
	public static function getByName($name)
	{
		return self::table(__CLASS__)->selectFirstObject('*', "vl_name='".self::escape($name)."'");
	}

	################
	### Creation ###
	################
	/**
	* Create new Voting table. Name is an identifier for yourself, for example module links has all voting table named as link_%d. An expire time of 0 means no expire
	* @param string $name
	* @param int $min
	* @param int $max
	* @param int $expire_time
	* @param int $options
	* @return GWF_VoteScore
	*/
	public static function newVoteLike($name, $expire_time=null, $options=0)
	{
		# Valid expire time.
		if (!is_int($expire_time))
		{
			$expire_time = 0;
		}
		else
		{
			$expire_time = ($expire_time > 0) ? $expire_time + time() : 0;
		}

		if (false !== ($vs = self::getByName($name)))
		{
			if (false === $vs->resetVotes($expire_time, $options))
			{
				return false;
			}
			return $vs;
		}

		return new self(array(
			'vl_id' => '0',
			'vl_name' => $name,
			'vl_date' => GWF_Time::getDate(self::DATE_LEN),
			'vl_expire_date' => $expire_time === 0 ? null : GWF_Time::getDate(self::DATE_LEN, $expire_time),
			'vl_options' => $options,
			'vl_likes' => '0',
			'vl_dislikes' => '0',
		));
	}

	public function resetVotes($expire_time, $options)
	{
		$id = $this->getVar('vs_id');
		if (false === GDO::table('GWF_VoteLikeRow')->deleteWhere("vlr_vlid=$id"))
		{
			return false;
		}
		return $this->saveVars(array(
			'vl_expire_date' => $expire_time <= 0 ? null : GWF_Time::getDate(self::DATE_LEN, $expire_time),
			'vl_options' => $options,
			'vl_likes' => '0',
			'vl_dislikes' => '0',
		));
	}

	public function resetVotesSameSettings()
	{
		if (!($expire_time = $this->getVar('vl_expire_date')))
		{
			$expire_time = 0;
		}
		else
		{
			$expire_time = GWF_Time::getTimestamp($expire_time);
		}
		return $this->resetVotes($expire_time, $this->getOptions());
	}

	############
	### Vote ###
	############
	public function onLike(GWF_User $user, $ip)
	{
		return $this->onVoteSafe(1, $user->getID(), $ip);
	}
	
	public function onDislike(GWF_User $user, $ip)
	{
		return $this->onVoteSafe(0, $user->getID(), $ip);
	}

	/**
	 * Vote and revert votes safely. Return false or error msg.
	 * @param int $score
	 * @param int $userid
	 * @return error msg or false
	 */
	public function onVoteSafe($score, $userid, $ip)
	{
		$score = Common::clamp((int)$score, 0, 1);
		$userid = (int)$userid;
		$vlid = $this->getID();
		$vlrid = 0;
	
		# Revert Guest Vote with same IP
		if ($vlr = GWF_VoteLikeRow::getByIP($vlid, $ip))
		{
			if (!$vlr->isGuestVoteExpired(GWF_Module::getModule('Votes')->cfgGuestTimeout()))
			{
				if (!$this->revertVote($vlr, $ip, 0))
				{
					return GWF_HTML::err('ERR_DATABASE', array( __FILE__, __LINE__));
				}
				$vlrid = $vlr->getID();
			}
		}
	
		# Revert Users Vote
		if ($vlr = GWF_VoteLikeRow::getByUID($vlid, $userid))
		{
			if ($vlrid !== $vlr->getID())
			{
				if (!$this->revertVote($vsr, 0, $userid))
				{
					return GWF_HTML::err('ERR_DATABASE', array( __FILE__, __LINE__));
				}
			}
		}
	
		# And Vote it
		if (!$this->onVote($score, $userid, $ip))
		{
			return GWF_HTML::err('ERR_DATABASE', array( __FILE__, __LINE__));
		}
	
		return false; # No error
	}
	
	public function onVote($score, $userid, $ip)
	{
		$vsr = new GWF_VoteLikeRow(array(
			'vlr_vlid' => $this->getID(),
			'vlr_uid' => $userid,
			'vlr_ip' => $ip,
			'vlr_time' => time(),
			'vlr_score' => $score,
		));
		$likes = $score > 0 ? 1 : 0;
		$dislikes = $score > 0 ? 0 : 1;
		return $vsr->insert() ? $this->countVote($vsr, $likes, $dislikes) : false;
	}

	public function hasVoted(GWF_User $user, $ip)
	{
		return $this->hasVotedIP($ip) || $this->hasVotedUser($user);
	}
	
	private function hasVotedIP($ip)
	{
		return GWF_VoteLikeRow::getByIP($this->getID(), $ip) !== false;
	}

	private function hasVotedUser(GWF_User $user)
	{
		return GWF_VoteScoreRow::getByUID($this->getID(), $user->getID()) !== false;
	}

	################
	### Rollback ###
	################
	public function revertVote(GWF_VoteLikeRow $row)
	{
		$score = $row->getScore();
		$likes = $score > 0 ? 0 : 1;
		$dislikes = $score > 0 ? 1 : 0;
		return $this->countVote($row, $likes, $dislikes) ?  $row->delete() : false;
	}
	
	private function countVote(GWF_VoteLikeRow $row, $likes, $dislikes)
	{
		return $this->saveVars(array(
			'vl_likes' => $this->getLikes() + $likes,
			'vl_dislikes' => $this->getDislikes() + $dislikes,
		));
	}

// 	###############
// 	### Display ###
// 	###############
// 	public function displayButtons()
// 	{
// 		if (false === ($module = GWF_Module::getModule('Votes'))) {
// 			return '';
// 		}
// 		$module instanceof Module_Votes;
// 		return $module->templateVoteScore($this);
// 	}

// 	public function displayPercent()
// 	{
// 		return sprintf('%.02f%%', $this->getAvgPercent());
// 	}

	#############
	### HREFs ###
	#############
	public function hrefLike()
	{
		return GWF_WEB_ROOT.'like/'.$this->getVar('vl_id');
	}
	
	public function hrefDislike()
	{
		return GWF_WEB_ROOT.'dislike/'.$this->getVar('vl_id');
	}
	
// 	public function hrefButton($score, $size='16')
// 	{
// 		return sprintf('%slikes/button/%s/%s.png', GWF_WEB_ROOT, $size, $score);
// 	}

	############
	### Ajax ###
	############
	public function getOnClick($score)
	{
		$vlid = $this->getVar('vl_id');
		return "gwfVoteLike($vlid, $score); return false;";
	}

	##############
	### Delete ###
	##############
	/**
	* Delete this voting table and it`s votes.
	* @return boolean
	*/
	public function onDelete()
	{
		if (false === GDO::table('GWF_VoteScoreRow')->deleteWhere('vlr_vlid='.$this->getID()))
		{
			return false;
		}

		return $this->delete();
	}

// 	/**
// 	 * Refresh the votescore from votescorerows.
// 	 */
// 	public function refreshCache()
// 	{
// 		$vlid = $this->getID();
// 		if (false === ($result = GDO::table('GWF_VoteScoreRow')->selectFirst("AVG(vsr_score), SUM(vsr_score), COUNT(*)", "vsr_vsid={$vlid}", '', NULL, GDO::ARRAY_N)))
// 		{
// 			return false;
// 		}
// 		return $this->saveVars(array(
// 			'vs_avg' => $result[0] === NULL ? $this->getInitialAvg() : $result[0],
// 			'vs_sum' => $result[1],
// 			'vs_count' => $result[2],
// 		));
// 	}
}
