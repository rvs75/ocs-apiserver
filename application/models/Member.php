<?php

/**
 *  ocs-apiserver
 *
 *  Copyright 2016 by pling GmbH.
 *
 *    This file is part of ocs-apiserver.
 *
 *    This program is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU Affero General Public License as
 *    published by the Free Software Foundation, either version 3 of the
 *    License, or (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 **/
class Application_Model_Member extends Application_Model_DbTable_Member
{

    /**
     * @param int    $count
     * @param string $orderBy
     * @param string $dir
     *
     * @return Zend_Db_Table_Rowset
     * @throws Zend_Exception
     */
    public function fetchNewActiveMembers($count = 100, $orderBy = 'created_at', $dir = 'DESC')
    {
        if (empty($count)) {
            return $this->generateRowSet($this->createRow());
        }

        $allowedDirection = array('desc' => true, 'asc' => true);
        if (false == isset($allowedDirection[strtolower($dir)])) {
            $dir = null;
        }

        /** @var Zend_Cache_Core $cache */
        $cache = Zend_Registry::get('cache');
        $cacheName = __FUNCTION__ . md5($count . $orderBy . $dir);
        $members = $cache->load($cacheName);

        if ($members) {
            return $members;
        } else {

            $sql = '
              SELECT count(*) AS total_count
              FROM member
              WHERE `is_active` = :activeVal
                 AND `type` = :typeVal
               AND `profile_image_url` <> :defaultImgUrl
               AND `profile_image_url` LIKE :likeImgUrl
          ';

            $resultCnt = $this->_db->fetchRow($sql, array(
                'activeVal'     => Application_Model_Member::MEMBER_ACTIVE,
                'typeVal'       => Application_Model_Member::MEMBER_TYPE_PERSON,
                'defaultImgUrl' => 'hive/user-pics/nopic.png',
                'likeImgUrl'    => 'hive/user-bigpics/0/%'
            ));

            $totalcnt = $resultCnt['total_count'];

            if ($totalcnt > $count) {
                $offset = ' offset ' . rand(0, $totalcnt - $count);
            } else {
                $offset = '';
            }

            $sql = '
                SELECT *
                FROM member
                WHERE `is_active` = :activeVal
                   AND `type` = :typeVal
            	   AND `profile_image_url` <> :defaultImgUrl
                 AND `profile_image_url` LIKE :likeImgUrl
            ';
            //$sql .= ' ORDER BY ' . $this->_db->quoteIdentifier($orderBy) . ' ' . $dir;

            $sql .= ' LIMIT ' . $this->_db->quote($count, Zend_Db::INT_TYPE);
            $sql .= $offset;

            $resultMembers = $this->getAdapter()->query($sql, array(
                'activeVal'     => Application_Model_Member::MEMBER_ACTIVE,
                'typeVal'       => Application_Model_Member::MEMBER_TYPE_PERSON,
                'defaultImgUrl' => 'hive/user-pics/nopic.png',
                'likeImgUrl'    => 'hive/user-bigpics/0/%'
            ))->fetchAll()
            ;

            $resultSet = $this->generateRowSet($resultMembers);

            $cache->save($resultSet, $cacheName, array(), 14400);

            return $resultSet;
        }
    }

    /**
     * @param $data
     *
     * @return Zend_Db_Table_Rowset
     */
    protected function generateRowSet($data)
    {
        $classRowSet = $this->getRowsetClass();

        $returnRowSet = new $classRowSet(array(
            'table'    => $this,
            'rowClass' => $this->getRowClass(),
            'stored'   => true,
            'data'     => $data
        ));

        return $returnRowSet;
    }

    /**
     * @return array
     * @deprecated
     */
    public function getMembersForSelectList()
    {
        $selectArr =
            $this->_db->fetchAll("SELECT member_id,username,firstname, lastname FROM {$this->_name} WHERE is_active=1 AND is_deleted=0 ORDER BY username");

        $arrayModified = array();

        $arrayModified[0] = "Benutzer wählen";
        foreach ($selectArr as $item) {
            $tmpStr = ($item['firstname']) ? $item['firstname'] : "";
            $tmpStr .= ($item['lastname']) ? ", " . $item['lastname'] : "";
            $tmpStr = ($tmpStr != "") ? " (" . $tmpStr . ")" : "";

            $arrayModified[$item['member_id']] = stripslashes($item['username'] . $tmpStr);
        }

        return $arrayModified;
    }

    /**
     * @param int $member_id
     *
     * @return boolean returns true if successful
     */
    public function activateMemberFromVerification($member_id, $verification_value)
    {
        $sql = "
            UPDATE member
              STRAIGHT_JOIN member_email ON member.member_id = member_email.email_member_id AND member_email.email_checked IS NULL AND member.is_deleted = 0 AND member_email.email_deleted = 0
            SET member.mail_checked = 1, member.is_active = 1, member.changed_at = NOW(), member_email.email_checked = NOW()
            WHERE member.member_id = :memberId AND member_email.email_verification_value = :verificationValue;
        ";
        $stmnt = $this->_db->query($sql, array('memberId' => $member_id, 'verificationValue' => $verification_value));

        return $stmnt->rowCount() > 0 ? true : false;
    }

    /**
     * @param int $member_id
     */
    public function setDeleted($member_id)
    {
        $updateValues = array(
            'is_active'  => 0,
            'is_deleted' => 1,
            'deleted_at' => new Zend_Db_Expr('Now()'),
        );

        $this->update($updateValues, $this->_db->quoteInto('member_id=?', $member_id, 'INTEGER'));

        $this->setMemberProjectsDeleted($member_id);
        $this->setMemberCommentsDeleted($member_id);
        $this->setMemberRatingsDeleted($member_id);
        $this->setMemberReportingsDeleted($member_id);
        $this->setMemberEmailsDeleted($member_id);
        //$this->setMemberPlingsDeleted($member_id);
        //$this->removeMemberProjectsFromSearch($member_id);
        $this->setDeletedInMaterializedView($member_id);
    }

    private function setMemberProjectsDeleted($member_id)
    {
        $modelProject = new Application_Model_Project();
        $modelProject->setAllProjectsForMemberDeleted($member_id);
    }

    private function setMemberCommentsDeleted($member_id)
    {
        $modelComments = new Application_Model_ProjectComments();
        $modelComments->setAllCommentsForUserDeleted($member_id);
    }

    private function setMemberRatingsDeleted($member_id)
    {
        $modelRatings = new Application_Model_DbTable_ProjectRating();
        $affectedRows = $modelRatings->setDeletedByMemberId($member_id);
        if (false === empty($affectedRows)) {
            $modelProject = new Application_Model_DbTable_Project();
            $modelProject->deleteLikes($affectedRows);
        }
    }

    private function setMemberReportingsDeleted($member_id)
    {
        $modelReportsProject = new Application_Model_DbTable_ReportProducts();
        $modelReportsProject->setDeleteByMember($member_id);
        $modelReportsComments = new Application_Model_DbTable_ReportComments();
        $modelReportsComments->setDeleteByMember($member_id);
    }

    private function setMemberEmailsDeleted($member_id)
    {
        $modelEmail = new Application_Model_DbTable_MemberEmail();
        $modelEmail->setDeletedByMember($member_id);
    }

    /**
     * @param int $member_id
     * @deprecated since we're using solr server for searching
     */
    private function removeMemberProjectsFromSearch($member_id)
    {
        $modelProject = new Application_Model_Project();
        $memberProjects = $modelProject->fetchAllProjectsForMember($member_id);
        $modelSearch = new Application_Model_Search_Lucene();
        foreach ($memberProjects as $memberProject) {
            $product = array();
            $product['project_id'] = $memberProject->project_id;
            $product['project_category_id'] = $memberProject->project_category_id;
            $modelSearch->deleteDocument($product);
        }
    }

    private function setDeletedInMaterializedView($member_id)
    {
        $sql = "UPDATE stat_projects SET status = :new_status WHERE member_id = :member_id";

        $this->_db->query($sql,
            array('new_status' => Application_Model_DbTable_Project::PROJECT_DELETED, 'member_id' => $member_id))->execute()
        ;
    }

    /**
     * @param int $member_id
     */
    public function setActivated($member_id)
    {
        throw new Zend_Db_Exception('not implemented yet.');

        $updateValues = array(
            'is_active'  => 1,
            'is_deleted' => 0,
            'changed_at' => new Zend_Db_Expr('Now()'),
            'deleted_at' => null
        );

        $this->update($updateValues, $this->_db->quoteInto('member_id=?', $member_id, 'INTEGER'));

        $this->setMemberProjectsActivated($member_id);
        $this->setMemberCommentsActivated($member_id);
        //$this->setMemberPlingsActivated($member_id);
    }

    private function setMemberProjectsActivated($member_id)
    {
        $modelProject = new Application_Model_Project();
        $modelProject->setAllProjectsForMemberActivated($member_id);
    }

    private function setMemberCommentsActivated($member_id)
    {
        $modelComment = new Application_Model_ProjectComments();
        $modelComment->setAllCommentsForUserActivated($member_id);
    }

    /**
     * @param int $member_id
     *
     * @return Zend_Db_Table_Row
     */
    public function fetchMemberData($member_id)
    {
        if (null === $member_id) {
            return null;
        }

        $sql = '
                SELECT 
                    `member`.*
                FROM
                    `member`
                WHERE
                    (member_id = :memberId) AND (is_deleted = :deletedVal)
        ';

        $result =
            $this->getAdapter()->query($sql, array('memberId' => $member_id, 'deletedVal' => self::MEMBER_NOT_DELETED))
                 ->fetch()
        ;

        $classRow = $this->getRowClass();

        return new $classRow(array('table' => $this, 'stored' => true, 'data' => $result));
    }

    /**
     * @param      $member_id
     * @param bool $active
     * @param bool $deleted
     *
     * @return null|Zend_Db_Table_Row_Abstract
     */
    public function fetchMember($member_id, $active = true, $deleted = false)
    {
        if (empty($member_id)) {
            return null;
        }

        $sql =
            'SELECT * FROM member WHERE is_deleted = :deleted AND is_active = :active AND member.member_id = :memberId';
        $stmnt = $this->_db->query($sql, array('deleted' => $deleted, 'active' => $active, 'memberId' => $member_id));

        if ($stmnt->rowCount() == 0) {
            return null;
        }

        return $this->generateRowClass($stmnt->fetch());
    }

    /**
     * @param string $user_name
     *
     * @return Zend_Db_Table_Row
     */
    public function fetchMemberFromHiveUserName($user_name)
    {
        $sql = "
                SELECT *
                FROM member
        		WHERE source_id = :sourceId
                  AND username = :userName
                ";

        return $this->_db->fetchRow($sql,
            array('sourceId' => Application_Model_Member::SOURCE_HIVE, 'userName' => $user_name));
    }

    /**
     * @param int $member_id
     * @param int $limit
     *
     * @return Zend_Db_Table_Rowset
     */
    public function fetchFollowedMembers($member_id, $limit = null)
    {
        $sql = "
                SELECT member_follower.member_id,
                       member_follower.follower_id,
                       member.*
                FROM member_follower
                LEFT JOIN member ON member_follower.member_id = member.member_id
        		WHERE member_follower.follower_id = :followerId
                  AND member.is_active = :activeVal
                GROUP BY member_follower.member_id
                ORDER BY max(member_follower.member_follower_id) DESC
                ";

        if (null != $limit) {
            $sql .= $this->_db->quoteInto(" limit ?", $limit, 'INTEGER');
        }

        $result = $this->_db->fetchAll($sql, array('followerId' => $member_id, 'activeVal' => self::MEMBER_ACTIVE));

        return $this->generateRowSet($result);
    }

    /**
     * @param int  $member_id
     * @param null $limit
     *
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function fetchFollowedProjects($member_id, $limit = null)
    {
        $sql = "
                SELECT project_follower.project_id,
                        project.title,
                        project.image_small                                              
                FROM project_follower
                  JOIN project ON project_follower.project_id = project.project_id                 
                  WHERE project_follower.member_id = :member_id
                  AND project.status = :project_status
                  AND project.type_id = 1               
                ORDER BY project_follower.project_follower_id DESC
                ";

        if (null != $limit) {
            $sql .= $this->_db->quoteInto(" limit ?", $limit, 'INTEGER');
        }

        $result = $this->_db->fetchAll($sql,
            array('member_id' => $member_id, 'project_status' => Application_Model_Project::PROJECT_ACTIVE));

        return $this->generateRowSet($result);
    }

    public function fetchPlingedProjects($member_id, $limit = null)
    {
        $sql = "
                SELECT project_category.title AS catTitle,
                       project.*,
        			   member.*,
    				   plings.*
                FROM plings
                LEFT JOIN project ON plings.project_id = project.project_id
                LEFT JOIN project_category ON project.project_category_id = project_category.project_category_id
        		LEFT JOIN member ON project.member_id = member.member_id
        		WHERE plings.member_id = :member_id
    			  AND plings.status_id = 2
                  AND project.status = :project_status
                  AND project.type_id = 1
                ORDER BY plings.create_time DESC
                ";

        if (null != $limit) {
            $sql .= $this->_db->quoteInto(" limit ?", $limit, 'INTEGER');
        }

        $result = $this->_db->fetchAll($sql,
            array('member_id' => $member_id, 'project_status' => Application_Model_Project::PROJECT_ACTIVE));

        return $this->generateRowSet($result);
    }

    public function fetchProjectsSupported($member_id, $limit = null)
    {
        $sql = "
                SELECT project_category.title AS catTitle,
                       project.project_id,
                       project.title,
                       project.image_small,
                       plings.member_id,
                       plings.amount,
                       plings.create_time,
                       member.profile_image_url,
                       member.username

                FROM plings
                JOIN project ON plings.project_id = project.project_id
                JOIN project_category ON project.project_category_id = project_category.project_category_id
                JOIN member ON plings.member_id = member.member_id
                WHERE project.member_id = :member_id
                  AND plings.status_id = 2
                  AND project.status = :project_status
                  AND project.type_id = 1
                ORDER BY plings.create_time DESC
                ";

        if (null != $limit) {
            $sql .= $this->_db->quoteInto(" limit ?", $limit, 'INTEGER');
        }

        $result = $this->_db->fetchAll($sql,
            array('member_id' => $member_id, 'project_status' => Application_Model_Project::PROJECT_ACTIVE));

        return $this->generateRowSet($result);
    }

    public function createNewUser($userData)
    {
        $uuidMember = Local_Tools_UUID::generateUUID();

        if (false == isset($userData['password'])) {
            throw new Exception(__METHOD__ . ' - user password is not set.');
        }
        $userData['password'] = Local_Auth_Adapter_Ocs::getEncryptedPassword($userData['password'],
            Application_Model_DbTable_Member::SOURCE_LOCAL);
        if (false == isset($userData['roleId'])) {
            $userData['roleId'] = self::ROLE_ID_DEFAULT;
        }
        if ((false == isset($userData['avatar'])) OR (false == isset($userData['profile_image_url']))) {
            $imageFilename = $this->generateIdentIcon($userData, $uuidMember);
            $userData['avatar'] = $imageFilename;
            $userData['profile_image_url'] = IMAGES_MEDIA_SERVER . '/cache/200x200-2/img/' . $imageFilename;
        }
        if (false == isset($userData['uuid'])) {
            $userData['uuid'] = $uuidMember;
        }

        return $this->storeNewUser($userData);
    }

    /**
     * @param $userData
     * @param $uuidMember
     *
     * @return string
     */
    protected function generateIdentIcon($userData, $uuidMember)
    {
        $identIcon = new Local_Tools_Identicon();
        $tmpImagePath = IMAGES_UPLOAD_PATH . 'tmp/' . $uuidMember . '.png';
        imagepng($identIcon->renderIdentIcon(sha1($userData['mail']), 1100), $tmpImagePath);

        $imageService = new Application_Model_DbTable_Image();
        $imageFilename = $imageService->saveImageOnMediaServer($tmpImagePath);

        return $imageFilename;
    }

    /**
     * @param array $userData
     *
     * @return Zend_Db_Table_Row_Abstract
     */
    public function storeNewUser($userData)
    {
        $newUserData = $this->createRow($userData);
        $newUserData->save();

        //create a user specified main project in project table
        $projectId = $this->storePersonalProject($newUserData->toArray());

        //and save the id in member table
        $newUserData->main_project_id = $projectId;
        $newUserData->save();

        return $newUserData;
    }

    /**
     * @param array $userData
     *
     * @return mixed $projectId
     */
    protected function storePersonalProject($userData)
    {
        $tableProject = new Application_Model_Project();
        /** @var Application_Model_DbRow_Project $newPersonalProject */
        $newPersonalProject = $tableProject->createRow($userData);
        $newPersonalProject->uuid = Local_Tools_UUID::generateUUID();
        $newPersonalProject->project_category_id = $newPersonalProject::CATEGORY_DEFAULT_PROJECT;
        $newPersonalProject->status = $newPersonalProject::STATUS_PROJECT_ACTIVE;
        $newPersonalProject->image_big = $newPersonalProject::DEFAULT_AVATAR_IMAGE;
        $newPersonalProject->image_small = $newPersonalProject::DEFAULT_AVATAR_IMAGE;
        $newPersonalProject->creator_id = $userData['member_id'];
        $newPersonalProject->title = $newPersonalProject::PERSONAL_PROJECT_TITLE;
        $projectId = $newPersonalProject->save();

        return $projectId;
    }

    public function fetchTotalMembersCount()
    {
        $sql = "
                SELECT
                    count(1) AS total_member_count
                FROM
                    member
               ";

        $result = $this->_db->fetchRow($sql);

        return $result['total_member_count'];
    }

    public function fetchTotalMembersInStoreCount()
    {
        $sql = "
                SELECT
                    count(1) AS total_member_count
                FROM
                    member
               ";

        $result = $this->_db->fetchRow($sql);

        return $result['total_member_count'];
    }

    /**
     * @param string $email
     *
     * @return null|Zend_Db_Table_Row_Abstract
     */
    public function fetchCheckedActiveLocalMemberByEmail($email)
    {
        $sel = $this->select()->where('mail=?', $email)
                    ->where('is_deleted = ?', Application_Model_DbTable_Member::MEMBER_NOT_DELETED)
                    ->where('is_active = ?', Application_Model_DbTable_Member::MEMBER_ACTIVE)
                    ->where('mail_checked = ?', Application_Model_DbTable_Member::MEMBER_MAIL_CHECKED)
                    ->where('login_method = ?', Application_Model_DbTable_Member::MEMBER_LOGIN_LOCAL)
        ;

        return $this->fetchRow($sel);
    }

    public function fetchEarnings($member_id, $limit = null)
    {
        $sql = "
                SELECT project_category.title AS catTitle,
                       project.*,
                       member.*,
                       plings.*
                FROM plings
                 JOIN project ON plings.project_id = project.project_id
                 JOIN project_category ON project.project_category_id = project_category.project_category_id
                 JOIN member ON project.member_id = member.member_id
                WHERE plings.status_id = 2
                  AND project.status = :status
                  AND project.type_id = 1
                  AND project.member_id = :memberId
                ORDER BY plings.create_time DESC
                ";

        if (null != $limit) {
            $sql .= $this->_db->quoteInto(" limit ?", $limit, 'INTEGER');
        }

        $result = $this->_db->fetchAll($sql,
            array('memberId' => $member_id, 'status' => Application_Model_Project::PROJECT_ACTIVE));

        return $this->generateRowSet($result);
    }

    /**
     * Finds an active user by given username or email ($identity)
     *
     * @param string $identity could be the username or users mail address
     * @param bool   $withLoginLocal
     *
     * @return Zend_Db_Table_Row_Abstract
     */
    public function findActiveMemberByIdentity($identity, $withLoginLocal = false)
    {
        $sqlName = "SELECT * FROM member WHERE is_active = :active AND is_deleted = :deleted AND username = :identity";
        $sqlMail = "SELECT * FROM member WHERE is_active = :active AND is_deleted = :deleted AND mail = :identity";
        if ($withLoginLocal) {
            $sqlName .= " AND login_method = '" . self::MEMBER_LOGIN_LOCAL . "'";
            $sqlMail .= " AND login_method = '" . self::MEMBER_LOGIN_LOCAL . "'";
        }
        $resultName = $this->getAdapter()->fetchRow($sqlName,
            array('active' => self::MEMBER_ACTIVE, 'deleted' => self::MEMBER_NOT_DELETED, 'identity' => $identity))
        ;
        $resultMail = $this->getAdapter()->fetchRow($sqlMail,
            array('active' => self::MEMBER_ACTIVE, 'deleted' => self::MEMBER_NOT_DELETED, 'identity' => $identity))
        ;

        if ((false !== $resultName) AND (count($resultName) > 0)) {
            return $this->generateRowClass($resultName);
        }
        if ((false !== $resultMail) AND (count($resultMail) > 0)) {
            return $this->generateRowClass($resultMail);
        }

        return $this->createRow();
    }

    /**
     * @param Zend_Db_Table_Row_Abstract $memberData
     *
     * @return bool
     */
    public function isHiveUser($memberData)
    {
        if (empty($memberData)) {
            return false;
        }
        if ($memberData->source_id == self::SOURCE_HIVE) {
            return true;
        }

        return false;
    }

    public function fetchActiveHiveUserByUsername($username)
    {
        $sql =
            'SELECT * FROM member WHERE username = :username AND is_active = 1 AND member.source_id = 1 AND member.is_deleted = 0';

        $result = $this->getAdapter()->query($sql, array('username' => $username))->fetch();

        return $result;
    }

    /**
     * @param      $member_id
     * @param null $limit
     *
     * @return Zend_Paginator
     */
    public function fetchComments($member_id, $limit = null)
    {
        $sql = '
            SELECT
                comment_id
                ,comment_text
                ,member.member_id
                ,profile_image_url
                ,comment_created_at
                ,username
                ,comment_target_id
                ,title
                ,project_id               
            FROM comments
            STRAIGHT_JOIN member ON comments.comment_member_id = member.member_id
            JOIN project ON comments.comment_target_id = project.project_id AND comments.comment_type = 0
            WHERE comments.comment_active = :comment_status
            AND project.status = :project_status
            AND comments.comment_member_id = :member_id
            ORDER BY comments.comment_created_at DESC
        ';

        if (isset($limit)) {
            $sql .= ' limit ' . (int)$limit;
        }
        $result = $this->_db->fetchAll($sql, array(
            'member_id'      => $member_id,
            'project_status' => Application_Model_DbTable_Project::PROJECT_ACTIVE,
            'comment_status' => Application_Model_DbTable_Comments::COMMENT_ACTIVE
        ));

        if (count($result) > 0) {
            return new Zend_Paginator(new Zend_Paginator_Adapter_Array($result));
        } else {
            return new Zend_Paginator(new Zend_Paginator_Adapter_Array(array()));
        }
    }

    public function fetchCntSupporters($member_id)
    {
        $sql = '
                SELECT DISTINCT plings.member_id FROM plings
                 JOIN project ON plings.project_id = project.project_id                
                 JOIN member ON project.member_id = member.member_id
                WHERE plings.status_id = 2
                  AND project.status = :project_status
                  AND project.type_id = 1
                  AND project.member_id = :member_id
            ';
        $result = $this->_db->fetchAll($sql,
            array('member_id' => $member_id, 'project_status' => Application_Model_Project::PROJECT_ACTIVE));

        return count($result);
    }

    public function fetchSupporterDonationInfo($member_id)
    {
        $sql='SELECT max(active_time) AS active_time_max 
                            ,min(active_time)  AS active_time_min 
                            ,(DATE_ADD(max(active_time), INTERVAL 1 YEAR) > now()) AS issupporter
                            ,count(1)  AS cnt from support  where status_id = 2  AND member_id = :member_id ';
        $result = $this->getAdapter()->fetchRow($sql,array('member_id' => $member_id));
        return $result; 
    }

    public function fetchLastActiveTime($member_id)
    {
        $sql_page_views =
            "SELECT created_at AS lastactive FROM stat_page_views WHERE member_id = :member_id ORDER BY created_at DESC LIMIT 1";
        $sql_activities =
            "SELECT `time` AS lastactive FROM activity_log WHERE member_id = :member_id ORDER BY `time` DESC LIMIT 1";

        $result_page_views = $this->getAdapter()->fetchRow($sql_page_views, array('member_id' => $member_id));
        $result_activities = $this->getAdapter()->fetchRow($sql_activities, array('member_id' => $member_id));

        if (count($result_page_views) > 0 AND count($result_activities) > 0) {
            return $result_page_views['lastactive'] > $result_activities['lastactive']
                ? $result_page_views['lastactive'] : $result_activities['lastactive'];
        }
        if (count($result_page_views) > count($result_activities)) {
            return $result_page_views['lastactive'];
        }
        if (count($result_activities) > count($result_page_views)) {
            return $result_activities['lastactive'];
        }

        return null;
    }

    /**
     * @param int $member_id
     *
     * @return array
     */
    public function fetchContributedProjectsByCat($member_id)
    {
        $projects = $this->fetchSupportedProjects($member_id);
        $catArray = array();
        if (count($projects) == 0) {
            return $catArray;
        }

        foreach ($projects as $pro) {
            $catArray[$pro->catTitle] = array();
        }

        $helperProductUrl = new Application_View_Helper_BuildProductUrl();
        foreach ($projects as $pro) {
            $projArr = array();
            $projArr['id'] = $pro->project_id;
            $projArr['name'] = $pro->title;
            $projArr['image'] = $pro->image_small;
            $projArr['url'] = $helperProductUrl->buildProductUrl($pro->project_id, '', null, true);
            $projArr['sumAmount'] = $pro->sumAmount;
            array_push($catArray[$pro->catTitle], $projArr);
        }

        return $catArray;
    }

    /**
     * @param int  $member_id
     * @param null $limit
     *
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function fetchSupportedProjects($member_id, $limit = null)
    {
        $sql = "
                SELECT plings.project_id,                       
                       project.title,
                       project.image_small,                       
                       project_category.title AS catTitle,                       
                       (SELECT SUM(amount) FROM plings WHERE plings.project_id=project.project_id AND plings.status_id=2) AS sumAmount
                FROM plings
                 JOIN project ON plings.project_id = project.project_id
                 JOIN project_category ON project.project_category_id = project_category.project_category_id                 
                WHERE plings.status_id IN (2,3,4)
                  AND plings.member_id = :member_id
                  AND project.status = :project_status
                  AND project.type_id = 1
                GROUP BY plings.project_id
                ORDER BY sumAmount DESC
                ";

        if (null != $limit) {
            $sql .= $this->_db->quoteInto(" limit ?", $limit, 'INTEGER');
        }

        $result = $this->_db->fetchAll($sql,
            array('member_id' => $member_id, 'project_status' => Application_Model_Project::PROJECT_ACTIVE));

        return $this->generateRowSet($result);
    }

}
