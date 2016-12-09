<?php
/**
 * One like.
 * @author gizmore
 */
final class GWF_VoteLikeRow extends GDO
{
	###########
	### GDO ###
	###########
	public function getClassName() { return __CLASS__; }
	public function getTableName() { return GWF_TABLE_PREFIX.'vote_like_row'; }
	public function getColumnDefines()
	{
		return array(
			'vlr_vlid' => array(GDO::UINT|GDO::INDEX, GDO::NOT_NULL),
			'vlr_uid' => array(GDO::UINT|GDO::INDEX, GDO::NOT_NULL),
			'vlr_ip' => GWF_IP6::gdoDefine(GWF_IP_QUICK, 0),
			'vlr_time' => array(GDO::UINT, GDO::NOT_NULL),
			'vlr_score' => array(GDO::UINT|GDO::TINY, GDO::NOT_NULL),
		
			# Join user table
			'users' => array(GDO::JOIN, GDO::NOT_NULL, array('GWF_User', 'vlr_uid', 'user_id')),
		);
	}
	
	public function isLike() { return $this->getScore() > 0; }
	public function isDislike() { return $this->getScore() <= 0; }
	public function getScore() { return $this->getVar('vlr_score'); }
	#####################
	### Static Getter ###
	#####################
	/**
	 * @param int $vsid
	 * @param int $uid
	 * @return GWF_VoteScoreRow
	 */
	public static function getByUID($vlid, $uid)
	{
		$vlid = (int) $vlid;
		$uid = (int) $uid;
		return self::table(__CLASS__)->selectFirstObject('*', "vlr_vlid=$vlid AND vlr_uid=$uid");
	}

	/**
	 * @param int $vsid
	 * @param int $ip
	 * @return GWF_VoteScoreRow
	 */
	public static function getByIP($vlid, $ip)
	{
		$vlid = (int) $vlid;
		$ip = self::escape($ip);
		return self::table(__CLASS__)->selectFirstObject('*', "vlr_vlid=$vlid AND vlr_ip='$ip'");
	}
	
// 	####################
// 	### Guest Expire ###
// 	####################
// 	public function isGuestVoteExpired($time)
// 	{
// 		return $this->getVar('vsr_time') < (time() - $time);
// 	}
}
