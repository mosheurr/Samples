<?php


namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\NotificationController;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\Planning\Project;
use Auth;
use App\User;
use Response;

class ProjectController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }
    public function saveProject(Request $request)
    {

        $sDate = \DateTime::createFromFormat('d/m/Y', $request->start_date);
        $eDate = \DateTime::createFromFormat('d/m/Y', $request->end_date);
        
        $lang = (Session::get('locale'))? Session::get('locale') : 'nl';app()->setLocale($lang);
        $saved = trans('message.Project Saved');$notSaved = trans('message.Project Not Saved');
        
        $validator = Validator::make($request->all(), [
            "name" => "required",
            "start_date" => "required",
            "end_date" => "required",
            "category_id" => "required",
            "subcategory_id" => "required",
        ]);
        if ($validator->fails()) {
            $type="error";$data = '<div><div class="alert alert-success"><span id="error-message-content">Given Data are invalid</span></div></div>';
        }
        $currentUser = Auth::user();$currentUserId = $currentUser->id; $currentUserName = $currentUser->username; $currentCompanId = $currentUser->company_id;
        if(request('owner_by')){
        $userActions = User::find(request('owner_by'));$user_actions = ($userActions->action_menus) ? json_decode($userActions->action_menus) : [];
        $action_cat = ActionMainCat::find(request('category_id'));$action_sl = $action_cat->serial_number;
        if(!in_array($action_sl, $user_actions)){
            $type="warning";$data = '<div><div class="alert alert-success"><span id="error-message-content">'.$notSaved.'</span></div></div>';
            $notification = array( 'message' => $data,'type' => $type);return Response::json($notification);
        }}
        try {
            $projectID = NULL;
            $project = new Project();
            $project->name = request('name');
            $project->company_id = $currentCompanId;
            $project->status = request('addProSts');
            $project->category_id = request('category_id');
            $project->subcategory_id = request('subcategory_id');
            $project->start_date = $sDate->format('Y-m-d');
            $project->end_date = $eDate->format('Y-m-d');
            $project->description = request('description');
            $project->owner_by = request('owner_by');
            $project->visible_by = request('visible_by');
            $project->editable_by = request('editable_by');
            $project->employees = ($request->employees) ? json_encode(explode(",", $request->employees)) : NULL;
            $project->freelancers = ($request->freelancers) ? json_encode(explode(",", $request->freelancers)) : NULL;
            $project->created_by = $currentUserId;
            $project->updated_by =$currentUserId;
            
            if ($project->save()) {
                if($currentUser->company->modules && in_array('notification',json_decode($currentUser->company->modules))){
                    $empPart = $project->employees;$freePart = $project->freelancers;
                    $receivers = array_merge($empPart, $freePart);
                    if(($key = array_search($project->created_by, $receivers)) !== false) { unset($receivers[$key]);}
                    if(count($receivers) > 0){
                        $mid = $project->id;$mname = $project->name;
                        $route = route('viewListProject',['id'=>$project->id,'page'=>'project']);
                        NotificationController::savedDeletedAction(array_values($receivers),$currentUserId,$currentUserName,'Project',$mid,$mname,$route,'create');
                    }
                }
                $employees = $persons = [];
                if ($request->filled('account_employees')) { $employees = json_decode($request->account_employees); }
                if ($request->filled('account_persons')) { $persons = json_decode($request->account_persons);}
                $accounts = array_merge($employees, $persons);
                if (count($accounts)) { $project->accounts()->attach($accounts); }
                Session::flash('loadProject', true);Session::flash("projectId", $project->id);
                $type="success";$projectID = $project->id;
                $data = '<div><div class="alert alert-success"><span id="error-message-content">'.$saved.'</span></div></div>';
            } else {
                $type="error";$data = '<div><div class="alert alert-success"><span id="error-message-content">test</span></div></div>';
            }
        } catch (\Exception $ex) {
            $type="error";$data = '<div><div class="alert alert-success"><span id="error-message-content">'.$notSaved.'</span></div></div>';
        }
        $notification = array(
             'message' => $data,'type' => $type,'id' => $projectID,
         );
        return Response::json($notification);
    }
    public function updateProject(Request $request)
    {
        $sDate = \DateTime::createFromFormat('d/m/Y', $request->start_date);
        $eDate = \DateTime::createFromFormat('d/m/Y', $request->end_date);
        $lang = (Session::get('locale'))? Session::get('locale') : 'nl';app()->setLocale($lang);
        $updated = trans('message.Project Updated');$notUpdated = trans('message.Project Not Updated');
        
        $currentUser = Auth::user();$currentUserId = $currentUser->id; $currentUserName = $currentUser->username;
        $role = ($currentUser->role)? $currentUser->role_id : 5;
        
        if(request('owner_by')){
        $userActions = User::find(request('owner_by'));$user_actions = ($userActions->action_menus) ? json_decode($userActions->action_menus) : [];$action_sl = $request->category_id;
        if(!in_array($action_sl, $user_actions)){
            $type="warning";$data = '<div><div class="alert alert-success"><span id="error-message-content">'.$notUpdated.'</span></div></div>';
            $notification = array( 'message' => $data,'type' => $type);
            return Response::json($notification);
        }}
        
        $project = Project::where('id', $request->id)->first();
        
        $oldOwner = $project->owner_by;$changed_details = [];
        
        if (!empty($project))
        {
            $validator = Validator::make($request->all(), [ "name" => "required","editProSts" => "required"]);
            if ($validator->fails())
            {
                $type="error";$data = '<div><div class="alert alert-success"><span id="error-message-content">Given data are Invalid</span></div></div>';
            }
            try {
                $projectID= NULL;
                $security = ($currentUserId == $project->created_by || $role==1 || $role==3 || $role==4) ? 1 : 0;
                if($currentUser->company->modules && in_array('notification',json_decode($currentUser->company->modules))){
                    $oldParticipants = array_merge($project->employees, $project->freelancers);
                    if($project->name != $request->name ){  array_push($changed_details, 'name');}
                    if($project->description != $request->description ){  array_push($changed_details, 'desc');}
                    if($oldOwner != $request->owner_by){  array_push($changed_details, 'owner');}
                }
                $project->name = request('name');
                $project->status = request('editProSts');
                $project->description = request('description');
                $lang = (Session::get('locale'))? Session::get('locale') : 'nl';
                $langid = Language::where('code',$lang)->value('id');
                if($request->appsec!="action")
                {
                    $catSub = LanguageController::changeCatSub($project->category_id,request('category_id'),$project->subcategory_id,request('subcategory_id'),$langid);
                    $project->category_id= $catSub["catid"];
                    $project->subcategory_id= $catSub["subid"]; 
                    
                    if($request->category_id != $catSub["oldcatsl"] ){  array_push($changed_details, 'category'); array_push($changed_details, 'subcategory'); }
                    else if($request->subcategory_id != $catSub["oldsubcatsl"] ){  array_push($changed_details, 'subcategory');}
                }
                if($security==1)
                {
                    $start_date = $sDate->format('Y-m-d');
                    $end_date =$eDate->format('Y-m-d'); 
                    
                    if($project->start_date != $start_date){  array_push($changed_details, 'sdate');}
                    if($project->end_date != $end_date ){  array_push($changed_details, 'edate');}
                    if($project->visible_by != $request->visible_by ){  array_push($changed_details, 'visible');}
                    if($project->editable_by != $request->editable_by ){  array_push($changed_details, 'editable');}
                    
                    $project->owner_by = request('owner_by');
                    $project->visible_by = request('visible_by');
                    $project->editable_by= request('editable_by');
                    $project->start_date = $start_date;
                    $project->end_date = $end_date;
                }
                $project->employees = ($request->uemployees) ? json_encode(explode(",", $request->uemployees)) : NULL;
                $project->freelancers = ($request->ufreelancers) ? json_encode(explode(",", $request->ufreelancers)) : NULL;
                
                $project->updated_by = $currentUserId;
                
                if ($project->save()) 
                {
                    $partAdded = $partRemoved = $partUnchanged = $userList = [];
                    $addedCount = $removedCount = 0;
                    $newParticipants = array_merge($project->employees, $project->freelancers);
                    if($currentUser->company->modules && in_array('notification',json_decode($currentUser->company->modules))){
                        foreach($newParticipants as $newParticipant){
                            if(!in_array($newParticipant, $oldParticipants)){ 
                                $addedCount++; 
                                array_push($partAdded, $newParticipant);
                                array_push($userList, $newParticipant);
                            }
                            else{ 
                                array_push($partUnchanged, $newParticipant);
                            } 
                        }
                        foreach($oldParticipants as $oldParticipant){
                            if(!in_array($oldParticipant, $newParticipants)){
                                $removedCount++; 
                                array_push($partRemoved, $oldParticipant);
                            }
                            array_push($userList, $oldParticipant);
                        }
                        if($addedCount > 0 || $removedCount > 0){  array_push($changed_details, 'participant');   }
                    }
                    $oldAccounts = $project->accounts->pluck('id')->toArray();
                    $employees = $persons = []; 
                    if ($request->filled('account_employees')) { $employees = json_decode($request->account_employees); }
                    if ($request->filled('account_persons')) {  $persons = json_decode($request->account_persons,); }
                    $accounts = array_merge($employees, $persons);
                    $accChanges = array_merge(array_diff($accounts, $oldAccounts), array_diff($oldAccounts, $accounts));
                    if(count($accChanges) > 0){  array_push($changed_details, 'account');  } 
                    $project->accounts()->sync($accounts);
                    if($currentUser->company->modules && in_array('notification',json_decode($currentUser->company->modules))){
                        $project->changed_details =  (count($changed_details) > 0) ? json_encode($changed_details) : NULL;
                        $project->save();
                        if(!in_array($project->created_by, $userList)){ array_push($userList, $project->created_by); }
                        $route = route('viewListProject',['id'=>$project->id,'page'=>'project']);
                        if($oldOwner != $project->owner_by){
                            if(!in_array($oldOwner, $userList)){ array_push($userList, $oldOwner); }
                            NotificationController::ownerAction(array_values($userList),$partUnchanged,$partAdded,$partRemoved,$oldOwner,$project->created_by,$project->owner_by,$currentUserId,$currentUserName,'Project',$project->id,$project->name,$route);
                        }else{
                             NotificationController::updatedAction(array_values($userList),$partUnchanged,$partAdded,$partRemoved,$project->created_by,$currentUserId,$currentUserName,'Project',$project->id,$project->name,$route);
                        }
                    }
                    Session::flash('loadProject', true);Session::flash("projectId", $project->id);
                    $type="success";$projectID = $project->id;
                    $data = '<div><div class="alert alert-success"><span id="error-message-content">'.$updated.'</span></div></div>';
                } else {
                    $type="error";$data = '<div><div class="alert alert-success"><span id="error-message-content">'.$notUpdated.'</span></div></div>';
                }
            } catch (\Exception $ex) {
                $type="error";$data = '<div><div class="alert alert-success"><span id="error-message-content">'.$notUpdated.'</span></div></div>';
            }
        } else {
            $type="error";$data = '<div><div class="alert alert-success"><span id="error-message-content">No Project found</span></div></div>';
        }
         $notification = array(
             'message' => $data,'type' => $type,'id' => $projectID,
         );
        return Response::json($notification);
    }
    function multiDelete (Request $request)
    {
        $response='';
        $currentUser = Auth::user();$currentUserId = $currentUser->id; $currentUserName = $currentUser->username; $role = $currentUser->role_id;
        if($request->id)
        {
            $projectids = explode(',', $request->id);
            $projects = Project::whereIn('id', $projectids)->get();
            $i=0;
            foreach($projects as $project)
            {
                $i++;
                $result = $this->allowDelProject($project->id);
                if($result["success"]==1){
                    $security = ($currentUserId == $project->created_by || $role==1 || $role==3 || $role==4) ? 1 : 0;
                    if($security == 1){
                        $i++;
                        
                        $projectId = $project->id;$projectName = $project->name;
                        $empPart = $project->employees;$freePart = $project->freelancers;$createdBy = $project->created_by;
                        
                        $project->delete();
                        if($currentUser->company->modules && in_array('notification',json_decode($currentUser->company->modules))){
                            $receivers = array_merge($empPart, $freePart);
                            if(!in_array($createdBy, $receivers)){ array_push($receivers, $createdBy); }
                            if(($key = array_search($currentUserId, $receivers)) !== false) { unset($receivers[$key]);}
                            if(count($receivers) > 0){
                                $route = route('planningSub',['main'=>'projects']);
                                NotificationController::savedDeletedAction(array_values($receivers),$currentUserId,$currentUserName,'Project',$projectId,$projectName,$route,'delete');
                            }
                        }
                    }
                }
                $response= $result["response"];
            }
        }
        return $response;
    }
}

