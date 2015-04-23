<?php

namespace Adldap\Classes;

use Adldap\Collections\AdldapGroupCollection;
use Adldap\Objects\Group;
use Adldap\Adldap;

/**
 * Ldap Group management
 *
 * Class AdldapGroups
 * @package Adldap\classes
 */
class AdldapGroups extends AdldapBase
{
    /**
     * The groups object category string.
     *
     * @var string
     */
    public $objectCategory = 'group';

    /**
     * Returns a complete list of all groups in AD
     *
     * @param bool $includeDescription Whether to return a description
     * @param string $search Search parameters
     * @param bool $sorted Whether to sort the results
     * @return array|bool
     */
    public function all($includeDescription = false, $search = "*", $sorted = true)
    {
        return $this->search(null, $includeDescription, $search, $sorted);
    }

    /**
     * Finds a group and returns it's information.
     *
     * @param string $groupName The group name
     * @param array $fields The group fields to retrieve
     * @return array|bool
     */
    public function find($groupName, $fields = array())
    {
        return $this->adldap->search()
            ->select($fields)
            ->where('objectCategory', '=', $this->objectCategory)
            ->where('anr', '=', $groupName)
            ->first();
    }

    /**
     * Returns a complete list of the groups in AD based on a SAM Account Type
     *
     * @param int $sAMAaccountType The account type to return
     * @param array $select The fields you want to retrieve for each
     * @param bool $sorted Whether to sort the results
     * @return array|bool
     */
    public function search($sAMAaccountType = Adldap::ADLDAP_SECURITY_GLOBAL_GROUP, $select = array(), $sorted = true)
    {
        $this->adldap->utilities()->validateLdapIsBound();

        $search = $this->adldap->search()
            ->select($select)
            ->where('objectCategory', '=', 'group');

        if ($sAMAaccountType !== null)
        {
            $search->where('samaccounttype', '=', $sAMAaccountType);
        }

        if($sorted)
        {
            $search->sortBy('samaccountname', 'asc');
        }

        return $search->get();
    }

    /**
     * Obtain the group's distinguished name based on their group ID
     *
     * @param string $groupName
     * @return string|bool
     */
    public function dn($groupName)
    {
        $group = $this->info($groupName);

        if(is_array($group) && array_key_exists('dn', $group))
        {
            return $group['dn'];
        }

        return false;
    }

    /**
     * Add a group to a group
     *
     * @param string $parent The parent group name
     * @param string $child The child group name
     * @return bool
     */
    public function addGroup($parent,$child)
    {
        // Find the parent group's dn
        $parentGroup = $this->find($parent);

        if ($parentGroup["dn"] === NULL) return false;

        $parentDn = $parentGroup["dn"];

        // Find the child group's dn
        $childGroup = $this->info($child);

        if ($childGroup["dn"] === NULL) return false;

        $childDn = $childGroup["dn"];

        $add = array();
        $add["member"] = $childDn;

        return $this->connection->modAdd($parentDn, $add);
    }

    /**
     * Add a user to a group
     *
     * @param string $group The group to add the user to
     * @param string $user The user to add to the group
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return bool
     */
    public function addUser($group, $user, $isGUID = false)
    {
        // Adding a user is a bit fiddly, we need to get the full DN of the user
        // and add it using the full DN of the group

        // Find the user's dn
        $userDn = $this->adldap->user()->dn($user, $isGUID);

        if ($userDn === false) return false;

        // Find the group's dn
        $groupInfo = $this->info($group, array("cn"));

        if ($groupInfo[0]["dn"] === NULL) return false;

        $groupDn = $groupInfo[0]["dn"];

        $add = array();
        $add["member"] = $userDn;

        return $this->connection->modAdd($groupDn, $add);
    }

    /**
     * Add a contact to a group
     *
     * @param string $group The group to add the contact to
     * @param string $contactDn The DN of the contact to add
     * @return bool
     */
    public function addContact($group, $contactDn)
    {
        // To add a contact we take the contact's DN
        // and add it using the full DN of the group

        // Find the group's dn
        $groupInfo = $this->info($group, array("cn"));

        if ($groupInfo[0]["dn"] === NULL) return false;

        $groupDn = $groupInfo[0]["dn"];

        $add = array();
        $add["member"] = $contactDn;

        return $this->connection->modAdd($groupDn, $add);
    }

    /**
     * Create a group
     *
     * @param array $attributes Default attributes of the group
     * @return bool|string
     */
    public function create(array $attributes)
    {
        $group = new Group($attributes);

        $group->validateRequired();

        $group->setAttribute('container', array_reverse($group->getAttribute('container')));

        $add = array();

        $add["cn"] = $group->getAttribute("group_name");
        $add["samaccountname"] = $group->getAttribute("group_name");
        $add["objectClass"] = "Group";
        $add["description"] = $group->getAttribute("description");

        $container = "OU=" . implode(",OU=", $group->getAttribute("container"));

        $dn = "CN=" . $add["cn"] . ", " . $container . "," . $this->adldap->getBaseDn();

        return $this->connection->add($dn, $add);
    }

    /**
     * Delete a group account
     *
     * @param string $group The group to delete (please be careful here!)
     * @return bool|string
     */
    public function delete($group)
    {
        $this->adldap->utilities()->validateNotNull('Group', $group);

        $this->adldap->utilities()->validateLdapIsBound();

        $groupInfo = $this->info($group, array("*"));

        $dn = $groupInfo[0]['distinguishedname'][0];

        return $this->adldap->folder()->delete($dn);
    }

    /**
     * Rename a group
     *
     * @param string $group The group to rename
     * @param string $newName The new name to give the group
     * @param array $container
     * @return bool
     */
    public function rename($group, $newName, $container)
    {
        $info = $this->info($group);

        if ($info[0]["dn"] === NULL)
        {
            return false;
        } else
        {
            $groupDN = $info[0]["dn"];
        }

        $newRDN = 'CN='.$newName;

        // Determine the container
        $container = array_reverse($container);
        $container = "OU=" . implode(", OU=", $container);

        // Do the update
        $dn = $container.', '.$this->adldap->getBaseDn();

        return $this->connection->rename($groupDN, $newRDN, $dn, true);
    }

    /**
     * Remove a group from a group
     *
     * @param string $parent The parent group name
     * @param string $child The child group name
     * @return bool
     */
    public function removeGroup($parent , $child)
    {
        // Find the parent dn
        $parentGroup = $this->info($parent, array("cn"));

        if ($parentGroup[0]["dn"] === NULL) return false;

        $parentDn = $parentGroup[0]["dn"];

        // Find the child dn
        $childGroup = $this->info($child, array("cn"));

        if ($childGroup[0]["dn"] === NULL) return false;

        $childDn = $childGroup[0]["dn"];

        $del = array();
        $del["member"] = $childDn;

        return $this->connection->modDelete($parentDn, $del);
    }

    /**
     * Remove a user from a group
     *
     * @param string $group The group to remove a user from
     * @param string $user The AD user to remove from the group
     * @param bool $isGUID Is the username passed a GUID or a samAccountName
     * @return bool
     */
    public function removeUser($group, $user, $isGUID = false)
    {
        // Find the parent dn
        $groupInfo = $this->info($group, array("cn"));

        if ($groupInfo[0]["dn"] === NULL) return false;

        $groupDn = $groupInfo[0]["dn"];

        // Find the users dn
        $userDn = $this->adldap->user()->dn($user, $isGUID);

        if ($userDn === false)return false;

        $del = array();
        $del["member"] = $userDn;

        return $this->connection->modDelete($groupDn, $del);
    }

    /**
     * Remove a contact from a group
     *
     * @param string $group The group to remove a user from
     * @param string $contactDn The DN of a contact to remove from the group
     * @return bool
     */
    public function removeContact($group, $contactDn)
    {
        // Find the parent dn
        $groupInfo = $this->info($group, array("cn"));

        if ($groupInfo[0]["dn"] === NULL) return false;

        $groupDn = $groupInfo[0]["dn"];

        $del = array();
        $del["member"] = $contactDn;

        return $this->connection->modDelete($groupDn, $del);
    }

    /**
     * Return a list of groups in a group
     *
     * @param string $group The group to query
     * @param null $recursive Recursively get groups
     * @return array|bool
     */
    public function inGroup($group, $recursive = NULL)
    {
        $this->adldap->utilities()->validateLdapIsBound();

        // Use the default option if they haven't set it
        if ($recursive === NULL) $recursive = $this->adldap->getRecursiveGroups();

        // Search the directory for the members of a group
        $info = $this->info($group, array("member","cn"));

        $groups = $info[0]["member"];

        if ( ! is_array($groups)) return false;

        $groupArray = array();

        for ($i = 0; $i < $groups["count"]; $i++)
        {
            $filter = "(&(objectCategory=group)(distinguishedName=" . $this->adldap->utilities()->ldapSlashes($groups[$i]) . "))";

            $fields = array("samaccountname", "distinguishedname", "objectClass");

            $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

            $entries = $this->connection->getEntries($results);

            // not a person, look for a group
            if ($entries['count'] == 0 && $recursive === true)
            {
                $filter = "(&(objectCategory=group)(distinguishedName=" . $this->adldap->utilities()->ldapSlashes($groups[$i]) . "))";

                $fields = array("distinguishedname");

                $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

                $entries = $this->connection->getEntries($results);

                if ( ! isset($entries[0]['distinguishedname'][0])) continue;

                $subGroups = $this->inGroup($entries[0]['distinguishedname'][0], $recursive);

                if (is_array($subGroups))
                {
                    $groupArray = array_merge($groupArray, $subGroups);
                    $groupArray = array_unique($groupArray);
                }

                continue;
            }

             $groupArray[] = $entries[0]['distinguishedname'][0];
        }

        return $groupArray;
    }

    /**
     * Return a list of members in a group.
     *
     * @param string $group The group to query
     * @param array $fields The fields to retrieve for each member
     * @return array|bool
     */
    public function members($group, $fields = array())
    {
        $group = $this->info($group);

        if(is_array($group) && array_key_exists('member', $group))
        {
            $members = array();

            foreach($group['member'] as $member)
            {
                $members[] = $this->adldap->search()
                    ->setDn($member)
                    ->select($fields)
                    ->where('objectClass', '=', 'user')
                    ->where('objectClass', '=', 'person')
                    ->first();
            }

            return $members;
        }

        return false;
    }

    /**
     * Group Information. Returns an array of raw information about a group.
     * The group name is case sensitive
     *
     * @param string $groupName The group name to retrieve info about
     * @param array $fields Fields to retrieve
     * @return array|bool
     */
    public function info($groupName, $fields = array())
    {
        return $this->find($groupName, $fields);
    }

    /**
     * Group Information. Returns a collection.
     *
     * The group name is case sensitive.
     *
     * @param string $groupName The group name to retrieve info about
     * @param null $fields Fields to retrieve
     * @param bool $isGUID Is the groupName passed a GUID or a name
     * @return \Adldap\collections\AdldapGroupCollection|bool
     * @depreciated
     */
    public function infoCollection($groupName, $fields = NULL, $isGUID = false)
    {
        $info = $this->info($groupName, $fields, $isGUID);

        if ($info) return new AdldapGroupCollection($info, $this->adldap);

        return false;
    }

    /**
     * Return a complete list of "groups in groups"
     *
     * @param string $group The group to get the list from
     * @return array|bool
     */
    public function recursiveGroups($group)
    {
        $this->adldap->utilities()->validateNotNull('Group', $group);

        $stack = array();
        $processed = array();
        $retGroups = array();

        array_push($stack, $group); // Initial Group to Start with

        while (count($stack) > 0)
        {
            $parent = array_pop($stack);

            array_push($processed, $parent);

            $info = $this->info($parent, array("memberof"));

            if (isset($info[0]["memberof"]) && is_array($info[0]["memberof"]))
            {
                $groups = $info[0]["memberof"];

                if ($groups)
                {
                    $groupNames = $this->adldap->utilities()->niceNames($groups);

                    $retGroups = array_merge($retGroups, $groupNames); //final groups to return

                    foreach ($groupNames as $id => $groupName)
                    {
                        if ( ! in_array($groupName, $processed))
                        {
                            array_push($stack, $groupName);
                        }
                    }
                }
            }
        }

        return $retGroups;
    }

    /**
     * Returns a complete list of security groups in AD
     *
     * @param bool $includeDescription Whether to return a description
     * @param string $search Search parameters
     * @param bool $sorted Whether to sort the results
     * @return array|bool
     */
    public function allSecurity($includeDescription = false, $search = "*", $sorted = true)
    {
        return $this->search(Adldap::ADLDAP_SECURITY_GLOBAL_GROUP, $includeDescription, $search, $sorted);
    }

    /**
     * Returns a complete list of distribution lists in AD
     *
     * @param bool $includeDescription Whether to return a description
     * @param string $search Search parameters
     * @param bool $sorted Whether to sort the results
     * @return array|bool
     */
    public function allDistribution($includeDescription = false, $search = "*", $sorted = true)
    {
        return $this->search(Adldap::ADLDAP_DISTRIBUTION_GROUP, $includeDescription, $search, $sorted);
    }

    /**
     * Coping with AD not returning the primary group
     * http://support.microsoft.com/?kbid=321360
     *
     * This is a re-write based on code submitted by Bruce which prevents the
     * need to search each security group to find the true primary group
     *
     * @param string $groupId Group ID
     * @param string  $userId User's Object SID
     * @return bool
     */
    public function getPrimaryGroup($groupId, $userId)
    {
        $this->adldap->utilities()->validateNotNull('Group ID', $groupId);
        $this->adldap->utilities()->validateNotNull('User ID', $userId);

        $groupId = substr_replace($userId, pack('V', $groupId), strlen($userId) - 4,4);

        $filter = '(objectsid=' . $this->adldap->utilities()->getTextSID($groupId).')';

        $fields = array("samaccountname","distinguishedname");

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $entries = $this->connection->getEntries($results);

        if (isset($entries[0]['distinguishedname'][0]))
        {
            return $entries[0]['distinguishedname'][0];
        }

        return false;
    }

    /**
     * Coping with AD not returning the primary group
     * http://support.microsoft.com/?kbid=321360
     *
     * For some reason it's not possible to search on primarygrouptoken=XXX
     * If someone can show otherwise, I'd like to know about it :)
     * this way is resource intensive and generally a pain in the @#%^
     *
     * @deprecated deprecated since version 3.1, see get get_primary_group
     * @param string $groupId Group ID
     * @return bool|string
     */
    public function cn($groupId)
    {
        $this->adldap->utilities()->validateNotNull('Group ID', $groupId);

        $r = '';

        $filter = "(&(objectCategory=group)(samaccounttype=" . Adldap::ADLDAP_SECURITY_GLOBAL_GROUP . "))";

        $fields = array("primarygrouptoken", "samaccountname", "distinguishedname");

        $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

        $entries = $this->connection->getEntries($results);

        for ($i = 0; $i < $entries["count"]; $i++)
        {
            if ($entries[$i]["primarygrouptoken"][0] == $groupId)
            {
                $r = $entries[$i]["distinguishedname"][0];
                $i = $entries["count"];
            }
        }

        return $r;
    }
}