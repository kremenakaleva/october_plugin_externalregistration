<?php namespace Pensoft\Externalregistration\Models;

use Illuminate\Support\Facades\DB;
use Model;
use RainLab\User\Models\User;

/**
 * Registration Model
 */
class Registration extends Model
{
    use \October\Rain\Database\Traits\Validation;

    /**
     * @var string table associated with the model
     */
    public $table = 'pensoft_externalregistration_registrations';

    /**
     * @var array guarded attributes aren't mass assignable
     */
    protected $guarded = ['*'];

    /**
     * @var array fillable attributes are mass assignable
     */
    protected $fillable = [];

    /**
     * @var array rules for validation
     */
    public $rules = [];

    /**
     * @var array Attributes to be cast to native types
     */
    protected $casts = [];

    /**
     * @var array jsonable attribute names that are json encoded and decoded from the database
     */
    protected $jsonable = [];

    /**
     * @var array appends attributes to the API representation of the model (ex. toArray())
     */
    protected $appends = [];

    /**
     * @var array hidden attributes removed from the API representation of the model (ex. toArray())
     */
    protected $hidden = [];

    /**
     * @var array dates attributes that should be mutated to dates
     */
    protected $dates = [
        'created_at',
        'updated_at'
    ];

    /**
     * @var array hasOne and other relations
     */
    public $hasOne = [];
    public $hasMany = [];
    public $belongsTo = [
        'user' => 'RainLab\User\Models\User'
    ];
    public $belongsToMany = [];
    public $morphTo = [];
    public $morphOne = [];
    public $morphMany = [];
    public $attachOne = [];
    public $attachMany = [];


    function syncUsers(){
        $users = new User();
        $activeUsers = $users->where('is_activated', 'true')
            ->whereRaw('(arpha_id is null OR arpha_id = 0)')
            ->get();

        foreach ($activeUsers as $user){
            $topics = 'null';
            if($user->topics){
                $topics = 'string_to_array(\'' . implode(',', $user->topics) . '\', \',\')::int[]';
            }
            //check if users exist in arpha
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
                $topics = $this->cleanTopics($arphaUsers[0]->expertise_subject_categories, $user->topics);
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
        }
    }

    function cleanTopics($selectedArphaTopics, $userTopics){
        $users = new User();
        $selectedTopics = json_decode($selectedArphaTopics);
        if($userTopics){
            if(!$selectedTopics){
                $selectedTopics = $userTopics;
            }
            $my_array = array_map('intval', $userTopics);
            $to_remove = array_keys($users->getTopicsOptions());
            $clean = array_diff($selectedTopics, $to_remove);
            $result_topics = array_unique(array_merge($clean, $my_array));
            $topics = 'string_to_array(\'' . implode(',', $result_topics) . '\', \',\')::int[]';
        }else{
            if($selectedTopics){
                $to_remove = array_keys($users->getTopicsOptions());
                $clean = array_diff($selectedTopics, $to_remove);
                $topics = 'string_to_array(\'' . implode(',', $clean) . '\', \',\')::int[]';
            }else{
                $topics = 'null';
            }
        }
        return $topics;
    }


}
