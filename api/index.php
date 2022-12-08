<?php
ob_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Credentials: true');

define('SITE_URL', site_url());
require_once(ABSPATH . 'wp-admin/includes/user.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
define('ADMIN_EMAIL', 'admin@knoxweb.com');
/*
Plugin Name:API
Description:gfcgv
Version:1.0.0
Author:Ajay
*/

use Firebase\JWT\JWT;
use \Firebase\JWT\Key;

class REST_APIS extends WP_REST_Controller
{
  private $api_namespace;
  private $api_version;
  public function __construct()
  {
    $this->api_namespace = 'api/v';
    $this->api_version = '1';
    $this->required_capability = 'read';
    $this->init();
    /*------- Start: Validate Token Section -------*/
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
      if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        $this->user_token =  $matches[1];
      }
    }
  }
  /*------- End: Validate Token Section -------*/

  private function successResponse($message = '', $data = array(), $total = array())
  {
    $response = array();
    $response['status'] = "success";
    $response['message'] = $message;
    $response['data'] = $data;
    if (!empty($total)) {
      $response['total'] = $total;
    }
    return new WP_REST_Response($response, 200);
  }
  private function errorResponse($message = '', $type = 'ERROR', $statusCode = 200)
  {
    $response = array();
    $response['status'] = "error";
    $response['error_type'] = $type;
    $response['message'] = $message;
    return new WP_REST_Response($response, $statusCode);
  }
  private function isValidToken()
  {
    $this->user_id  = $this->getUserIdByToken($this->user_token);
  }

  public function register_routes()
  {
    $namespace = $this->api_namespace . $this->api_version;
    $privateItems = array('updateUserProfile','updatepost', 'addPost'); //Api Name 
    $publicItems  = array('register', 'aboutUs',  'change_password', 'create_categories', 'delete_categories', 'addstudentinfo');
    foreach ($privateItems as $Item) {
      register_rest_route(
        $namespace,
        '/' . $Item,
        array(
          array(
            'methods' => 'POST',
            'callback' => array($this, $Item),
            'permission_callback' => !empty($this->user_token) ? '__return_true' : '__return_false'
          ),
        )
      );
    }

    foreach ($publicItems as $Item) {
      register_rest_route(
        $namespace,
        '/' . $Item,
        array(
          array(
            'methods' => 'POST',
            'callback' => array($this, $Item)
          ),
        )
      );
    }
  }
  public function init()
  {
    add_action('rest_api_init', array($this, 'register_routes'));
    add_action('rest_api_init', function () {
      remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
      add_filter('rest_pre_serve_request', function ($value) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        return $value;
      });
    }, 15);
  }

  public function isUserExists($user)
  {
    global $wpdb;
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->users WHERE ID = %d", $user));
    if ($count == 1) {
      return true;
    } else {
      return false;
    }
  }

  public function getUserIdByToken($token)
  {

    $decoded_array = array();
    $user_id = 0;
    if ($token) {
      try {
        $decoded = JWT::decode($token, new Key(JWT_AUTH_SECRET_KEY, apply_filters('jwt_auth_algorithm', 'HS256')));

        $decoded_array = (array)$decoded;
        if (count($decoded_array) > 0) {
          $user_id = $decoded_array['data']
            ->user->id;
        }

        if ($this->isUserExists($user_id)) {
          return $user_id;
        } else {
          return false;
        }
      } catch (\Exception $e) { // Also tried JwtException
        return false;
      }
    }
  }

  public function jwt_auth($data, $user)
  {

    unset($data['user_nicename']);
    unset($data['user_display_name']);
    $site_url = site_url();
    // $result = $this->getProfile($user->ID);
    $tutorial = get_user_meta($user->ID, 'tutorial', true);

    $result['token'] =  $data['token'];
    return $this->successResponse('User Logged in successfully', $result);
  }

  // -------------------------------Register--------------------------------------

  public function register($request)
  {
    global $wpdb;
    $param = $request->get_params();
    // hospital data
    $first_name = $param['first_name'];
    $last_name = $param['last_name'];
    $email = $param['email'];
    $password = $param['password'];
    if (email_exists($email)) {
      return $this->errorResponse('Email already exists.');
    } else {
      // User Info     
      $user_id = wp_create_user($first_name, $password, $email);
      update_user_meta($user_id, 'first_name', $first_name);
      update_user_meta($user_id, 'last_name', $last_name);
      if (!empty($user_id)) {
        return $this->successResponse('User registration successfull.');
      } else {
        return $this->errorResponse('Please try again.');
      }
    }
  }

  // ---------------------------------------------aboutUs------------------------------

  public function aboutUs($request)
  {
    $args = array(
      'p' => $request['id'],
      'post_type' => 'page',
    );
    if (!$post = get_post($request['id'])) {
      return new WP_Error('invalid_id', 'Please define a valid post ID.');
    }

    $query = new WP_Query($args);
    if ($query->have_posts()) {
      $query->the_post();
      $post = get_post(get_the_ID());
      $id = get_the_ID();
      $title = $post->post_title;
      $results['id'] = $id;
      $results['title'] = $title;
      $results['post_content'] = stripslashes(strip_tags($post->post_content));
      $data[] = $results;
    }
    wp_reset_postdata();
    if (!empty($data)) {
      return $this->successResponse('', $data);
    } else {
      return $this->errorResponse('No record found');
    }
  }

  // -----------------------------------updateUserProfile--------------------------------------

  public function updateUserProfile($request)
  {
    global $wpdb;
    $param = $request->get_params();
    $this->isValidToken();

    $user_id = (!empty($this->user_id)) ? $this->user_id : $param['user_id'];
    $first_name = $param['first_name'];
    $last_name = $param['last_name'];
    $caregiver = $param['caregiver'];
    $profile_pic = $param['profile_pic'];

    if (!empty($user_id)) {
      update_user_meta($user_id, 'first_name', $first_name);
      update_user_meta($user_id, 'last_name', $last_name);
      update_user_meta($user_id, 'caregiver', $caregiver);
      update_user_meta($user_id, 'image', $profile_pic);
      return $this->successResponse('record updated successfully.');
    } else {
      return $this->errorResponse('No record found.');
    }
  }

  //------------------------------change_password-------------------------------------

  public function change_password($request)
  {
    global $wpdb;
    $param = $request->get_params();
    $user_login = trim($param['user_email']);
    $new_pass = trim($param['password']);
    if (empty($user_login)) {
      return $this->errorResponse('User email is empty');
    } elseif (!is_email($user_login)) {
      return $this->errorResponse('Please provide valid email');
    } elseif (strpos($user_login, '@')) {
      $user_data = get_user_by('email', trim($user_login));
    }

    if ($user_data) {
      $user_id = $user_data->ID;
      if ($user_id) {
        if (!empty($new_pass)) {
          $upadte_password = wp_set_password($new_pass, $user_id);
          update_user_meta($user_id, "is_verified", 0);
          return $this->successResponse('password changed successfully');
        }
      }
    } else {
      return $this->errorResponse("User not exists.");
    }
  }

  // ----------------------------------------addPost/andImage-------------------------------------------

  public function addPost($request)
  {
    global $wpdb;
    $param = $request->get_params();
    $title = $param['title'];
    $content = $param['content'];
    $publish = $param['status'];
    $author = $param['author'];
    $categories = $param['categories'];

    $this->isValidToken();
    $user_id = !empty($this->user_id) ? $this->user_id : $param['user_id'];
    if (!empty($user_id)) {
      $post_id = wp_insert_post(array(
        'post_title'    => $title,
        'post_content'  => $content,
        'post_status'   => $publish,
        'post_author'   => $author,
        'post_categories' => $categories
      ));

      if (!empty($_FILES['profile_pic'])) {
        $userProfileImgId = media_handle_upload('profile_pic', $user_id);
        update_post_meta($post_id, '_thumbnail_id', $userProfileImgId);
        wp_set_post_categories($post_id, $categories);
      }
      if (!empty($post_id)) {
        return $this->successResponse(' successfully');
      } else {
        return $this->errorResponse('post are not created.  ');
      }
    }
    if (!empty($user_id)) {
      return $this->successResponse(' successfully');
    } else {
      return $this->errorResponse('Failed ');
    }
  }

  // ----------------------------------updatepost/Image/categories--------------------------

  public function updatepost($request)
  {
    global $wpdb;
    $param = $request->get_params();
    $designation = $param['designation'];
    $company = $param['company'];
    $id = $param['id'];
    $content = $param['content'];
    $categories = $param['categories'];


    $this->isValidToken();
    $user_id = !empty($this->user_id) ? $this->user_id : $param['user_id'];
    if (!empty($user_id)) {
      $postId  = wp_update_post(array(
        'ID'   => $id,
        'post_designation' => $designation,
        'post_company' => $company,
        'post_content' => $content,
        'post_categories' => $categories,
      ));


      //get_post_meta()
      update_post_meta($postId, 'desination', $designation);
      update_post_meta($postId, 'comepny', $company);
      update_post_meta($postId, 'content', $content);
      wp_set_post_categories($id, $categories);

      if (!empty($_FILES['profile_pic']['tmp_name'])) {
        $userProfileImgId = media_handle_upload('profile_pic', $user_id);
        update_post_meta($postId, '_thumbnail_id', $userProfileImgId);
      } else {
        delete_post_meta($postId, "_thumbnail_id");
      }



      if (!empty($postId)) {
        return $this->successResponse('Profile Updated Successfully!');
      } else {
        return $this->errorResponse('No record found');
      }
    }
  }

  // -----------------------------createategories-------------------------------------

  public function create_categories($request)
  {
    global $wpdb;
    $param = $request->get_params();
    $this->isValidToken();
    $user_id = !empty($this->user_id) ? $this->user_id : $param['user_id'];
    $category_name = trim($param['category_name']);
    if (empty($user_id)) {
      return $this->errorResponse("Please enter valid token.");
    } else {
      $get_userData = get_userdata($user_id);
      $get_roles = $get_userData->roles[0];
      if ($get_roles == "administrator") {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $category_name));
        
        $args = array(
          "taxonomy" => "category",
          "post_type" => "music_games",
          "orderby"   => "name",
          "hide_empty" => false,
          "order"     => "DESC"
        );
       
        $get_cat = get_categories($args);
        $taxonomy_slug = array();
        foreach ($get_cat as $categories) {
          $taxonomy_slug[] = $categories->slug;
        }

        if (in_array($slug, $taxonomy_slug)) {
          return $this->errorResponse("Category already exists.");
        } else {
          wp_insert_term($category_name, 'category', array(
            "slug" => $slug
          ));

          return $this->successResponse("Category created successfully.");
        }
      } else {
        return $this->errorResponse("You don't have permission to access");
      }
    }
  }
  // -----------------------------------delete_categories---------------------------------------------

  public function delete_categories($request)
  {
    global $wpdb;
    $param = $request->get_params();
    $this->isValidToken();
    $user_id = !empty($this->user_id) ? $this->user_id : $param['user_id'];
    // print_r($user_id);
    // die;
    // $category_name = trim($param['category_name']);
    if (empty($user_id)) {
      return $this->errorResponse("Please enter valid token.");
    } else {
      $post_id = trim($param['post_id']);
      $cat_name = get_the_category($post_id); // print_r($cat_name);(with new id)// die;
      $term_id = $cat_name['0']->term_id;
      $taxonomy = $cat_name['0']->taxonomy;

      if (!empty($post_id)) {
        wp_remove_object_terms($post_id, $term_id, $taxonomy);
        return $this->successResponse("Category detele successfully.");
      } else {
        return $this->errorResponse("You don't have permission to access");
      }
    }
  }

  // --------------------------------------addstudentinfo------------------------------------

  public function addstudentinfo($request)
  {
    global $wpdb;
    $param = $request->get_params();

    $this->isValidToken();
    $user_id = !empty($this->user_id) ? $this->user_id : $param['user_id'];
    if (empty($user_id)) {
      return $this->errorResponse("Please enter valid token.");
    }
    $name = $param['name'];
    $dob = $param['dob'];
    $address = $param['address'];
    $city = $param['city'];
    $state = $param['state'];
    $zip_code = $param['zip_code'];

    $tablename = 'wp_import_data';

    $data = array(
      'name' => $name,
      'dob' => $dob,
      'address' => $address,
      'city' => $city,
      'state' => $state,
      'zip_code' => $zip_code,
    );
    $serialize_info = serialize($data);
    $wpdb->insert($tablename, array(
      "user_Id" => $user_id,
      "user_info" => $serialize_info
    ));
    return $this->successResponse("Data inserted successfully.");
  }
}


$serverApi = new REST_APIS();
$serverApi->init();
add_filter('jwt_auth_token_before_dispatch', array($serverApi, 'jwt_auth'), 10, 2);
