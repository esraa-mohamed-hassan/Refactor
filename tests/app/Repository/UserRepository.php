<?php

namespace DTApi\Repository;

use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\Type;
use DTApi\Models\UsersBlacklist;
use DTApi\Models\User;
use DTApi\Models\Town;
use DTApi\Models\UserMeta;
use DTApi\Models\UserTowns;
use DTApi\Models\UserLanguages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;

class UserRepository extends BaseRepository
{
    protected $model;
    protected $logger;

    /**
     * @param User $model
     */
    public function __construct(User $model)
    {
        parent::__construct($model);

        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function createOrUpdate($id = null, $request)
    {
        $model = is_null($id) ? new User : User::findOrFail($id);
        $this->fillUserData($model, $request);
        $model->detachAllRoles();
        $model->save();
        $model->attachRole($request['role']);

        if ($request['role'] == env('CUSTOMER_ROLE_ID')) {
            $this->updateCustomerMeta($model, $request);
            $this->updateUserBlacklist($model, $request);
        } elseif ($request['role'] == env('TRANSLATOR_ROLE_ID')) {
            $this->updateTranslatorMeta($model, $request);
            $this->updateUserLanguages($model, $request);
        }

        $this->updateUserTowns($model, $request);

        if ($request['status'] == '1') {
            $this->enable($model->id);
        } else {
            $this->disable($model->id);
        }

        return $model ? $model : false;
    }

    private function fillUserData($model, $request)
    {
        $model->user_type = $request['role'];
        $model->name = $request['name'];
        $model->company_id = $request['company_id'] != '' ? $request['company_id'] : 0;
        $model->department_id = $request['department_id'] != '' ? $request['department_id'] : 0;
        $model->email = $request['email'];
        $model->dob_or_orgid = $request['dob_or_orgid'];
        $model->phone = $request['phone'];
        $model->mobile = $request['mobile'];

        if (!$model->exists || ($model->exists && $request['password'])) {
            $model->password = bcrypt($request['password']);
        }
    }

    private function updateCustomerMeta($model, $request)
    {
        $userMeta = UserMeta::firstOrCreate(['user_id' => $model->id]);
        $userMeta->fill([
            'consumer_type' => $request['consumer_type'],
            'customer_type' => $request['customer_type'],
            'username' => $request['username'],
            'post_code' => $request['post_code'],
            'address' => $request['address'],
            'city' => $request['city'],
            'town' => $request['town'],
            'country' => $request['country'],
            'reference' => isset($request['reference']) && $request['reference'] == 'yes' ? '1' : '0',
            'additional_info' => $request['additional_info'],
            'cost_place' => isset($request['cost_place']) ? $request['cost_place'] : '',
            'fee' => isset($request['fee']) ? $request['fee'] : '',
            'time_to_charge' => isset($request['time_to_charge']) ? $request['time_to_charge'] : '',
            'time_to_pay' => isset($request['time_to_pay']) ? $request['time_to_pay'] : '',
            'charge_ob' => isset($request['charge_ob']) ? $request['charge_ob'] : '',
            'customer_id' => isset($request['customer_id']) ? $request['customer_id'] : '',
            'charge_km' => isset($request['charge_km']) ? $request['charge_km'] : '',
            'maximum_km' => isset($request['maximum_km']) ? $request['maximum_km'] : '',
        ]);
        $userMeta->save();
        $this->updateUserBlacklist($model, $request);
    }

    private function updateUserBlacklist($model, $request)
    {
        $blacklistUpdated = [];
        $userBlacklist = UsersBlacklist::where('user_id', $model->id)->get();
        $userTranslId = collect($userBlacklist)->pluck('translator_id')->all();
        $diff = null;

        if ($request['translator_ex']) {
            $diff = array_intersect($userTranslId, $request['translator_ex']);
        }

        if ($diff || $request['translator_ex']) {
            foreach ($request['translator_ex'] as $translatorId) {
                $blacklist = new UsersBlacklist();

                if ($model->id) {
                    $alreadyExist = UsersBlacklist::translatorExist($model->id, $translatorId);

                    if ($alreadyExist == 0) {
                        $blacklist->user_id = $model->id;
                        $blacklist->translator_id = $translatorId;
                        $blacklist->save();
                    }

                    $blacklistUpdated[] = $translatorId;
                }
            }

            if ($blacklistUpdated) {
                UsersBlacklist::deleteFromBlacklist($model->id, $blacklistUpdated);
            }
        } else {
            UsersBlacklist::where('user_id', $model->id)->delete();
        }
    }

    private function updateTranslatorMeta($model, $request)
    {
        $userMeta = UserMeta::firstOrCreate(['user_id' => $model->id]);
        $userMeta->fill([
            'translator_type' => $request['translator_type'],
            'worked_for' => $request['worked_for'],
            'organization_number' => $request['worked_for'] == 'yes' ? $request['organization_number'] : null,
            'gender' => $request['gender'],
            'translator_level' => $request['translator_level'],
            'additional_info' => $request['additional_info'],
            'post_code' => $request['post_code'],
            'address' => $request['address'],
            'address_2' => $request['address_2'],
            'town' => $request['town'],
        ]);
        $userMeta->save();
    }

    private function updateUserLanguages($model, $request)
    {
        $langIdUpdated = [];

        if ($request['user_language']) {
            foreach ($request['user_language'] as $langId) {
                $userLang = new UserLanguages();
                $alreadyExist = $userLang::langExist($model->id, $langId);

                if ($alreadyExist == 0) {
                    $userLang->user_id = $model->id;
                    $userLang->lang_id = $langId;
                    $userLang->save();
                }

                $langIdUpdated[] = $langId;
            }

            if ($langIdUpdated) {
                $userLang::deleteLang($model->id, $langIdUpdated);
            }
        }
    }

    private function updateUserTowns($model, $request)
    {
        if ($request['new_towns']) {
            $towns = new Town;
            $towns->townname = $request['new_towns'];
            $towns->save();
            $newTownsId = $towns->id;
        }

        $townIdUpdated = [];

        if ($request['user_towns_projects']) {
            DB::table('user_towns')->where('user_id', $model->id)->delete();

            foreach ($request['user_towns_projects'] as $townId) {
                $userTown = new UserTowns();
                $alreadyExist = $userTown::townExist($model->id, $townId);

                if ($alreadyExist == 0) {
                    $userTown->user_id = $model->id;
                    $userTown->town_id = $townId;
                    $userTown->save();
                }

                $townIdUpdated[] = $townId;
            }
        }
    }

    public function enable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '1';
        $user->save();
    }

    public function disable($id)
    {
        $user = User::findOrFail($id);
        $user->status = '0';
        $user->save();
    }

    public function getTranslators()
    {
        return User::where('user_type', 2)->get();
    }
}
