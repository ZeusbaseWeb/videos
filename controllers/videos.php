<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * @author 		PyroCMS Dev Team
 * @package 	PyroCMS
 * @subpackage 	Modules
 * @category 	Videos
 */
class Videos extends Public_Controller
{
	public function __construct()
	{
		parent::__construct();
		
		$this->load->library('keywords/keywords');
		$this->load->model(array('video_m', 'video_channel_m', 'comments/comments_m'));
		$this->load->helper('text');
		$this->lang->load('video');

		list($this->template->thumb_width, $this->template->thumb_height)=explode('x', Settings::get('video_thumb_size'));
	}
	
	public function index()
	{
		$this->page();
	}

	// video/page/x also routes here
	public function page()
	{
		$where = array('schedule_on <=' => now());

		$pagination = create_pagination('videos/page', $this->video_m->where($where)->count_by(), NULL, 3);
		$result = $this->video_m->limit($pagination['limit'])->where($where)->get_all();
		
		// Slice shit up so it can go in the right channel (or sub channel)
		$videos = array();
		foreach ($result as $video)
		{
			// Give a bool from the JSON logic if the current user can see this video
			$video->restricted = ( ! $this->current_user and ($restrict = json_decode($video->restricted_to)) and ! empty($restrict[0]));
			
			$videos[$video->channel_id][] = $video;
		}
		unset($result);
		
		$result = $this->video_channel_m->get_all();
		$channels = array();
		foreach ($result as $channel)
		{
			$channels[$channel->parent_id][$channel->id] = $channel;
		}
		unset($result);
		
		$this->template
			->title($this->module_details['name'])
			->set_breadcrumb( lang('video:video_title'))
			->build('index', array(
				'videos' => $videos,
				'channels' => $channels,
				'pagination' => $pagination,
			));
	}

	// video/page/x also routes here
	public function search()
	{
		$query = $this->input->get_post('q');

		$pagination = create_pagination('videos/search', count($this->video_m->get_search($query)), NULL, 3);
		$videos = $this->video_m->limit($pagination['limit'])->get_search($query);

		$this->template
			->title($this->module_details['name'], 'Search', $query)
			->set_breadcrumb(lang('video:video_title'))
			->set_breadcrumb('Search', 'videos/search')
			->set_breadcrumb('"'.htmlentities($query).'"')
			->build('list', array(
				'videos' => $videos,
				'pagination' => $pagination,
			));
	}
	
	// video/tags/foo
	public function tags($tag)
	{
		$pagination = create_pagination('videos/tags/'.$tag, count($this->video_m->get_tag($tag)), NULL, 4);
		$videos = $this->video_m->limit($pagination['limit'])->get_tag($tag);

		$this->template
			->title($this->module_details['name'], 'Tags', $tag)
			->set_breadcrumb(lang('video:video_title'))
			->set_breadcrumb('Search')
			->build('list', array(
				'videos' => $videos,
				'pagination' => $pagination,
			));
	}

	public function channel($channel_slug = null)
	{
		$channel_slug OR redirect('videos');

		$channel = $this->video_channel_m->get_by(array('slug' => $channel_slug)) OR show_404();

		// Count total video videos and work out how many pages exist
		$pagination = create_pagination('video/channel/'.$channel_slug, $this->video_m->count_by(array(
			'channel' => $channel_slug,
			'schedule_on <=' => now(),
		)), NULL, 4);

		// Get the current page of video videos
		$videos = $this->video_m->limit($pagination['limit'])->get_many_by(array(
			'video_channels.id'=> $channel->id,
			'schedule_on <=' => now(),
		));
		
		// Find sub channels of this module
		$sub_channels = $this->video_channel_m->get_many_by('parent_id', $channel->id);

		// Build the page
		$this->template->title($this->module_details['name'], $channel->title )
			->set_metadata('description', $channel->description)
			->set_metadata('keywords', str_replace(' ', ', ', $channel->title))
			->set_breadcrumb( lang('video:videos_title'), 'videos')
			->set_breadcrumb( $channel->title )
			->set('videos', $videos)
			->set('channel', $channel)
			->set('sub_channels', $sub_channels)
			->set('pagination', $pagination)
			->build('channel');
	}
	
	public function subchannel($channel_slug = null, $sub_channel_slug = null)
	{
		$channel_slug OR redirect('videos');

		$parent = $this->video_channel_m->get_by('slug', $channel_slug) OR show_404();
		$channel = $this->video_channel_m->get_by('slug', $sub_channel_slug) OR show_404();

		// Count total video videos and work out how many pages exist
		$pagination = create_pagination('video/channel/'.$channel->slug.'/'.$sub_channel_slug, $this->video_m->count_by(array(
			'channel' => $channel->slug,
			'schedule_on <=' => now(),
		)), NULL, 5);
		
		$this->template->set('parent', $parent);

		// Get the current page of video videos
		$videos = $this->video_m->limit($pagination['limit'])->get_many_by(array(
			'video_channels.id'=> $channel->id,
			'schedule_on <=' => now(),
		));

		// Build the page
		$this->template->title($this->module_details['name'], $channel->title )
			->set_metadata('description', $channel->description)
			->set_metadata('keywords', str_replace(' ', ', ', $channel->title))
			->set_breadcrumb( lang('video:videos_title'), 'videos')
			->set_breadcrumb( $channel->title )
			->set('videos', $videos)
			->set('channel', $channel)
			->set('parent', $parent)
			->set('pagination', $pagination)
			->build('subchannel');
	}
	
	// Public: View an video
	public function view($slug = '')
	{
		if ( ! $slug or ! $video = $this->video_m->get_by('slug', $slug))
		{
			redirect('videos');
		}

		if ($video->schedule_on > now() && ! $this->ion_auth->is_admin())
		{
			redirect('videos');
		}

		if ( ! $channel = $this->video_channel_m->get($video->channel_id))
		{
			redirect('videos');
		}

		// Is this video restricted?
		if (($video->restricted_to = json_decode($video->restricted_to)) and $video->restricted_to[0] != "0") // hack
		{
			

			// Are they logged in and an admin or a member of the correct group?
			if ( ! $this->current_user)
			{
				redirect('users/login/videos/view/'.$video->slug);
			}
			
			elseif (isset($this->current_user->group) AND $this->current_user->group != 'admin' AND ! in_array($this->current_user->group_id, $video->restricted_to))
			{
				show_error('You do not have permission to view this video.');
			}
		}
		
		// Convert keywords into something useful
		$video->keywords = Keywords::get_array($video->keywords);
		
		// Find out how many videos are in this channel
		$channel->video_count = $this->video_channel_m->count_videos($channel->id);
		
		// They want it a difference size? Lets resize it!
		if (Settings::get('video_display_width') != $video->width)
		{
			$width = Settings::get('video_display_width');
			$ratio = $width / $video->width;

			$new_width = round($width);
			$new_height = round($video->height * $ratio);

			$video->embed_code = str_replace(array(
				'width="'.$video->width.'"',
				'height="'.$video->height.'"',
				'width:'.$video->width.'px',
				'height:'.$video->height.'px',
			), array(
				'width="'.$new_width.'"',
				'height="'.$new_height.'"',
				'width:'.$new_width.'px',
				'height:"'.$new_height.'px',
			), $video->embed_code);
		}

		$video->channel = $channel;

		$this->video_m->update_views($video->id);

		// Find videos with the same tag
		$related_videos = $this->video_m->get_related($video, 3);

		$channel_videos = $this->video_m->limit(3)->get_many_by(array(
			'channel_id' => $video->channel->id,
			'videos.id !=' => $video->id,
		));
		
		$this->template->title($video->title, $video->channel->title, lang('video:videos_title'))
			->set_metadata('description', $video->description)
			->set_metadata('keywords', implode(', ', $video->keywords))
			->set_breadcrumb(lang('video:videos_title'), 'videos')
			->set_breadcrumb($video->channel->title, 'video/channel/'.$video->channel->slug)
			->set_breadcrumb($video->title)
			->build('view', array(
				'video' => $video,
				'related_videos' => $related_videos,
				'channel_videos' => $channel_videos,
			));
	}

}
