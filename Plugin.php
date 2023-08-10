<?php namespace Pensoft\Externalregistration;

use Backend;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use October\Rain\Database\Model;
use Pensoft\Externalregistration\Models\Registration;
use RainLab\User\Components\Account;
use RainLab\User\Models\User;
use RainLab\User\Models\UserGroup;
use System\Classes\PluginBase;

/**
 * Externalregistration Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Externalregistration',
            'description' => 'No description provided yet...',
            'author'      => 'Pensoft',
            'icon'        => 'icon-leaf'
        ];
    }

    public function onRun(){


    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {

    }

    /**
     * Boot method, called right before the request route.
     *
     * @return void
     */
    public function boot()
    {

        if (class_exists('\Rainlab\User\Controllers\Users')) {
            \Rainlab\User\Controllers\Users::extendFormFields(function ($form) {
                $form->addTabFields([
                    'arpha_id' => [
                        'label' => 'ARPHA ID',
                        'span'  => 'auto',
                        'type'  => 'text',
                        'tab'  => 'rainlab.user::lang.user.account',
                        'default' => 0
                    ],
                    'topics' => [
                        'label' => 'Soil Mission Objectives',
                        'span'  => 'auto',
                        'type'  => 'checkboxlist',
                        'tab'  => 'rainlab.user::lang.user.account',
                    ]
                ]);
            });
        }

        // assign desired group to newly registered user
        \Event::listen('rainlab.user.register', function($user) {
            $user->addUserExternalGroup(UserGroup::whereCode('external-users')->first());
        });

        User::extend(function ($model) {

            $model->hasOne['profile'] = ['Pensoft\Externalregistration\Models\Registration'];

            $model->addJsonable('topics');
            $model->addFillable('topics');

            $model->addDynamicMethod('getTopicsOptions', function() {
                //get topics from ARPHA
                $topics = DB::connection('arpha')
                    ->select('SELECT DISTINCT(sc.id), sc.name
                        FROM subject_categories sc
                        JOIN subject_categories_byjournal scj on scj.id = sc.id and scj.journal_id = 122
                        ORDER BY sc.name;');
                $topicsArray = [];
                if(count($topics)){
                    foreach ($topics as $item){
                        $topicsArray[$item->id] = $item->name;
                    }
                }
                return $topicsArray;
            });

            $model->addDynamicMethod('addUserExternalGroup', function($group) use ($model) {
                if ($group instanceof Collection) {
                    return $model->groups()->saveMany($group);
                }

                if (is_string($group)) {
                    $group = UserGroup::whereCode($group)->first();

                    return $model->groups()->save($group);
                }

                if ($group instanceof UserGroup) {
                    return $model->groups()->save($group);
                }
            });

            $model->bindEvent('user.register', function ($group) use ($model) {
                if ($group instanceof Collection) {
                    return $model->groups()->saveMany($group);
                }

                if (is_string($group)) {
                    $group = UserGroup::whereCode($group)->first();

                    return $model->groups()->save($group);
                }

                if ($group instanceof UserGroup) {
                    return $model->groups()->save($group);
                }
            });

            $model->bindEvent('model.afterSave', function() use ($model) {
                if ($model->is_activated) { // update from admin
                    $userId = $model->id;
                    $user = User::find($userId);
                    $arphaUsers = DB::connection('arpha')
                        ->select('SELECT u2.id, array_to_json(u2.expertise_subject_categories) as expertise_subject_categories
                                FROM usr u
                                JOIN usr u2 ON u2.id = u.primary_uid
                                WHERE lower(u.uname) = \'' . strtolower(trim($user->email)) . '\';');
                    if(count($arphaUsers)){
                        $newUserId = (int)$arphaUsers[0]->id;
                        $topics = (new Registration())->cleanTopics($arphaUsers[0]->expertise_subject_categories, $user->topics);
                        DB::connection('arpha')->select('
                                    UPDATE usr
                                    SET state = 1,
                                    first_name = \'' . trim($user->name) . '\',
                                    last_name = \'' . trim($user->surname) . '\',
                                    modify_date =  now(),
                                    expertise_subject_categories = ' . $topics . '
                                    WHERE id = ' . (int)$newUserId . '');
                    }
                }
            });

        });

        // assign desired group to newly activated user
        \Event::listen('rainlab.user.activate', function($user) {
            $topics = 'null';
            if($user->topics){
                $topics = 'string_to_array(\'' . implode(',', $user->topics) . '\', \',\')::int[]';
            }
            //check if user exist in arpha
            $arphaUsers = DB::connection('arpha')
                ->select('SELECT u2.id, array_to_json(u2.expertise_subject_categories) as expertise_subject_categories
                                FROM usr u
                                JOIN usr u2 ON u2.id = u.primary_uid
                                WHERE lower(u.uname) = \'' . strtolower(trim($user->email)) . '\';');

            $ltmppass = md5(date("Y-m-d H:i:s") . strtolower(trim($user->email)));
            $ltmppass = substr($ltmppass, 1, 6);
            $lhash = md5(date("Y-m-d H:i:s") . $ltmppass); /* Hash za autologina */

            if(!count($arphaUsers)){
                $newArphaUser = DB::connection('arpha')->select('INSERT INTO usr(
					uname,
                    primary_uid,
					upass,
					first_name,
					last_name,
					state,
                    create_date,
                    modify_date,
                autolog_hash,
                expire_autolog_hash,
                plain_upass,
                expertise_subject_categories
                    )
                    VALUES
                    (
                        \'' . strtolower(trim($user->email)) . '\',
                        (SELECT currval(\'usr_id_seq\')),
                        \'' . md5(trim($user->password)) . '\',
                        \'' . trim($user->name) . '\',
                        \'' . trim($user->surname) . '\',
                        1,
                        now(),
                        now(),
                        \'' . $lhash . '\',
                        \'' . $lhash . '\',
                        \'' . $ltmppass . '\',
                        ' . $topics . '
                    ) RETURNING id;');

                $newUserId = (int)$newArphaUser[0]->id;
                $user->arpha_id = $newUserId;//TODO
                $user->save();
            }else{
                $newUserId = (int)$arphaUsers[0]->id;
                $topics = (new Registration())->cleanTopics($arphaUsers[0]->expertise_subject_categories, $user->topics);
                DB::connection('arpha')->select('UPDATE usr
                        SET
                            state = 1,
                            autolog_hash = COALESCE(autolog_hash, \'' . $lhash . '\'),
                            expire_autolog_hash = COALESCE(autolog_hash, \'' . $lhash . '\'),
                            plain_upass = COALESCE(autolog_hash, \'' . $ltmppass . '\'),
                            first_name = \'' . trim($user->name) . '\',
                            last_name = \'' . trim($user->surname) . '\',
                            expertise_subject_categories = ' . $topics . ',
                            modify_date = now()
                WHERE id = ' . (int)$newUserId . ' ');
                $user->arpha_id = $newUserId; //TODO
                $user->save();
            }

            $newJournalUser = DB::connection('arpha')->select('INSERT INTO pjs.journal_users ( journal_id, uid, role_id, display_in_groups, receive_email, type_id, state, trusted, unreliable)
                    SELECT 122, ' . (int)$newUserId . ', 10, true, true, 1, 1, 0, 0
                    WHERE NOT EXISTS(
                        SELECT * FROM pjs.journal_users WHERE journal_id = 122 AND uid = ' . (int)$newUserId . '  AND role_id = 10);');


        });

        \Event::listen('rainlab.user.update', function($user, $data) { // update from profile page
            $userId = $user->id;
            $userData = User::find($userId);
            $userData->topics = post('topics');
            $userData->save();

            //check if user exist in arpha
            $arphaUsers = DB::connection('arpha')
                ->select('SELECT u2.id, array_to_json(u2.expertise_subject_categories) as expertise_subject_categories
                                FROM usr u
                                JOIN usr u2 ON u2.id = u.primary_uid
                                WHERE lower(u.uname) = \'' . strtolower(trim($user->email)) . '\';');
            if(count($arphaUsers)){
                $ltmppass = md5(date("Y-m-d H:i:s") . strtolower(trim($user->email)));
                $ltmppass = substr($ltmppass, 1, 6);
                $lhash = md5(date("Y-m-d H:i:s") . $ltmppass); /* Hash za autologina */

                $newUserId = (int)$arphaUsers[0]->id;
                $topics = (new Registration())->cleanTopics($arphaUsers[0]->expertise_subject_categories, $userData->topics);
                DB::connection('arpha')->select('
                                    UPDATE usr
                                    SET state = 1,
                                    autolog_hash = COALESCE(autolog_hash, \'' . $lhash . '\'),
                                    expire_autolog_hash = COALESCE(autolog_hash, \'' . $lhash . '\'),
                                    plain_upass = COALESCE(autolog_hash, \'' . $ltmppass . '\'),
                                    first_name = \'' . trim($user->name) . '\',
                                    last_name = \'' . trim($user->surname) . '\',
                                    modify_date =  now(),
                                    expertise_subject_categories = ' . $topics . '
                                    WHERE id = ' . (int)$newUserId . '');
            }
        });

        \Event::listen('rainlab.user.login', function($user) {// User has logged in

            foreach ($user->groups as $group) {

                if ($group->code == 'internal-users') {
                    return \Redirect::to('/internal-documents');
                }

                if ($group->code == 'external-users') {
                    return \Redirect::to('/external-documents');
                }
            }
        });
    }

    /**
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        return []; // Remove this line to activate

        return [
            'Pensoft\Externalregistration\Components\MyComponent' => 'myComponent',
        ];
    }

    /**
     * Registers any back-end permissions used by this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return []; // Remove this line to activate

        return [
            'pensoft.externalregistration.some_permission' => [
                'tab' => 'Externalregistration',
                'label' => 'Some permission'
            ],
        ];
    }

    /**
     * Registers back-end navigation items for this plugin.
     *
     * @return array
     */
    public function registerNavigation()
    {
        return []; // Remove this line to activate

        return [
            'externalregistration' => [
                'label'       => 'Externalregistration',
                'url'         => Backend::url('pensoft/externalregistration/mycontroller'),
                'icon'        => 'icon-leaf',
                'permissions' => ['pensoft.externalregistration.*'],
                'order'       => 500,
            ],
        ];
    }
}
