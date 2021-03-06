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
 *
 *    Created: 22.09.2016
 **/
class Application_Model_MemberEmail
{
    /** @var string */
    protected $_dataTableName;
    /** @var  Application_Model_DbTable_MemberEmail */
    protected $_dataTable;

    /**
     * @inheritDoc
     */
    public function __construct($_dataTableName = 'Application_Model_DbTable_MemberEmail')
    {
        $this->_dataTableName = $_dataTableName;
        $this->_dataTable = new $this->_dataTableName;
    }

    /**
     * @param int $user_name
     * @param string $member_email
     * @return string
     */
    public static function getVerificationValue($user_name, $member_email)
    {
        return md5($user_name . $member_email . time());
    }

    /**
     * @param int $member_id
     * @param bool $email_deleted
     * @return array
     */
    public function fetchAllMailAdresses($member_id, $email_deleted = false)
    {
        $deleted = $email_deleted === true ? Application_Model_DbTable_MemberEmail::EMAIL_DELETED : Application_Model_DbTable_MemberEmail::EMAIL_NOT_DELETED;
        $sql = "SELECT * FROM {$this->_dataTable->info('name')} WHERE `email_member_id` = :memberId AND `email_deleted` = :emailDeleted";
        $stmnt = $this->_dataTable->getAdapter()->query($sql, array('memberId' => $member_id, 'emailDeleted' => $deleted));
        return $stmnt->fetchAll();
    }

    public function setDefaultEmail($emailId, $member_id)
    {
        $result = $this->resetDefaultMailAddress($member_id);
        $this->_dataTable->setPrimary($emailId);
        $this->updateMemberPrimaryMail($member_id); /* if we change the mail in member table, we change the login. */
        return true;
    }

    private function resetDefaultMailAddress($member_id)
    {
        $sql = "update member_email set email_primary = 0 where email_member_id = :member_id and email_primary = 1";
        return $this->_dataTable->getAdapter()->query($sql, array('member_id' => $member_id))->execute();
    }

    /**
     * @param string $verification
     * @return int count of updated rows
     */
    public function verificationEmail($verification)
    {
        $sql = "update member_email set `email_checked` = NOW() where `email_verification_value` = :verification and `email_deleted` = 0 and `email_checked` is null";
        $stmnt = $this->_dataTable->getAdapter()->query($sql, array('verification' => $verification));
        return $stmnt->rowCount();
    }

    /**
     * @param int $user_id
     * @param string $user_mail
     * @param null|string $user_verification
     * @return Zend_Db_Table_Row_Abstract
     */
    public function saveEmail($user_id, $user_mail, $user_verification = null)
    {
        $data = array();
        $data['email_member_id'] = $user_id;
        $data['email_address'] = $user_mail;
        $data['email_verification_value'] = empty($user_verification) ? Application_Model_MemberEmail::getVerificationValue($user_id, $user_mail) : $user_verification;

        return $this->_dataTable->save($data);
    }

    /**
     * @param int $user_id
     * @param string $user_mail
     * @param null|string $user_verification
     * @return Zend_Db_Table_Row_Abstract
     */
    public function saveEmailAsPrimary($user_id, $user_mail, $user_verification = null)
    {
        $data = array();
        $data['email_member_id'] = $user_id;
        $data['email_address'] = $user_mail;
        $data['email_verification_value'] = empty($user_verification) ? Application_Model_MemberEmail::getVerificationValue($user_id, $user_mail) : $user_verification;
        $data['email_primary'] = Application_Model_DbTable_MemberEmail::EMAIL_PRIMARY;

        $result = $this->_dataTable->save($data);

        $this->updateMemberPrimaryMail($user_id);

        return $result;
    }

    /**
     * @param $member_id
     * @return mixed
     */
    private function updateMemberPrimaryMail($member_id)
    {
        $dataEmail = $this->fetchMemberPrimaryMail($member_id);

        return $this->saveMemberPrimaryMail($member_id, $dataEmail);
    }

    /**
     * @param $member_id
     * @return mixed
     */
    public function fetchMemberPrimaryMail($member_id)
    {
        $sql = "select * from {$this->_dataTable->info('name')} where email_member_id = :member_id and email_primary = 1";
        $dataEmail = $this->_dataTable->getAdapter()->fetchRow($sql, array('member_id' => $member_id));
        return $dataEmail;
    }

    /**
     * @param $member_id
     * @param $dataEmail
     * @return mixed
     */
    protected function saveMemberPrimaryMail($member_id, $dataEmail)
    {
        $modelMember = new Application_Model_Member();
        $dataMember = $modelMember->fetchMemberData($member_id);
        $dataMember->mail = $dataEmail['email_address'];
        $dataMember->mail_checked = isset($dataEmail['email_checked']) ? 1 : 0;
        return $dataMember->save();
    }

}