<?php
class PHPTail {

    /**
     * Location of the log file we're tailing
     * @var string
     */
    private $log = "";
    /**
     * The time between AJAX requests to the server.
     *
     * Setting this value too high with an extremly fast-filling log will cause your PHP application to hang.
     * @var integer
     */
    private $updateTime;
    /**
     * This variable holds the maximum amount of bytes this application can load into memory (in bytes).
     * @var string
     */
    private $maxSizeToLoad;
	/**
	 * This variable holds a token used for authentication with Flex TV.
	 * @var string
	 */
	private $apiToken;
	/**
	 * Our line counter.
	 * @var int
	 */
	private $count;
    /**
     *
     * PHPTail constructor
     * @param string | array $log the location of the log file
     * @param integer $defaultUpdateTime The time between AJAX requests to the server.
     * @param integer $maxSizeToLoad This variable holds the maximum amount of bytes this application can load into memory (in bytes). Default is 2 Megabyte = 2097152 byte
     */
    public function __construct($log, $defaultUpdateTime = 1000, $maxSizeToLoad = 102097152,$token,$noHeader=false) {
        $this->log = is_array($log) ? $log : array($log);
        $this->updateTime = $defaultUpdateTime;
        $this->maxSizeToLoad = $maxSizeToLoad;
        $this->apiToken = $token;
        $this->count = 0;
        $this->noHeader = $noHeader;
    }

	function json_validate($string)
	{
		// decode the JSON data
		$result = json_decode($string,true);

		// switch and check possible JSON errors
		switch (json_last_error()) {
			case JSON_ERROR_NONE:
				$error = false;
				break;
			default:
				$error = true;
				break;
		}

		if ($error) {
			return false;
		}

		return $result;
	}
	
	/**
	 * This function is in charge of retrieving the latest lines from the log file
	 * @param string $lastFetchedSize The size of the file when we lasted tailed it.
	 * @param string $grepKeyword The grep keyword. This will only return rows that contain this word
	 * @return string Returns the JSON representation of the latest file size and appended lines.
	 */
    public function getNewLines($file, $lastFetchedSize, $grepKeyword, $invert,$count=false) {
		$json = false;
        /**
         * Clear the stat cache to get the latest results
         */
        clearstatcache();
        /**
         * Define how much we should load from the log file
         * @var
         */
        if(empty($file)) {
            $file = key(array_slice($this->log, 0, 1, true));
        } else {
        	$count = 0;
		}
        $fsize = filesize($this->log[$file]);
        $maxLength = ($fsize - $lastFetchedSize);
        /**
         * Verify that we don't load more data then allowed.
         */
        if($maxLength > $this->maxSizeToLoad) {
            $maxLength = ($this->maxSizeToLoad / 2);
        }
        /**
         * Actually load the data
         */
        $data = array();
        if($maxLength > 0) {

            $fp = fopen($this->log[$file], 'r');
            fseek($fp, -$maxLength , SEEK_END);
            $data = explode("\n", fread($fp, $maxLength));

        }
        /**
         * Run the grep function to return only the lines we're interested in.
         */
        if($invert == 0) {
            $data = preg_grep("/$grepKeyword/",$data);
        }
        else {
            $data = preg_grep("/$grepKeyword/",$data, PREG_GREP_INVERT);
        }

        	$i = 0;
        	$l = 0;
        	$newData = [];
        	foreach($data as $line) {
        		if (!trim($line)) continue;
        		if ($i === 0) {
        			$i = 1;
        			$linecolor = "white";
				} else {
        			$i = 0;
        			$linecolor = "grey";
				}
		        $authString = "; <?php die('Access denied'); ?>".PHP_EOL;
        		$line = str_replace($authString,"",$line);
		        $og = $line;
		        $level = "DEBUG";
		        if (strpos($line,"[ERROR]") !== false || strpos($line,"[php7:error]") !== false) $level = "ERROR";
		        if (strpos($line,"[INFO]") !== false || strpos($line,"[php7:notice]") !== false) $level = "INFO";
		        if (strpos($line,"[WARN]") !== false || strpos($line,"[php7:warn]") !== false) $level = "WARN";
                if (strpos($line,"[ALERT]") !== false || strpos($line,"[php7:alert]") !== false) $level = "ALERT";
		        $og = urlencode($og);
		        $substr = explode(": ",$line);
		        try {
                    if (count($substr) >= 2) {
                        unset($substr[0]);
                        $substr = implode(": ", $substr);
                        $test = $this->json_validate($substr);
                        if ($test) {
                            $json = json_encode($test) ?? false;
                            if ($json !== "null" && (!preg_match("/ERROR11/", $json))) {
                                $jsonLink = '<a href="" class="jsonParse" title="' . htmlentities($json) . '" data-json="' . urlencode($json) . '">[JSON]</a>';
                                $line = str_replace($substr, $jsonLink, $line);
                                $og = htmlentities($json);
                            }
                        }
                    }

                    preg_match('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $line, $match);
                    if (isset($match[0])) {
                        $parts = htmlentities(json_encode(parse_url($match[0])));
                        $link = "<a href='$match[0]' title='$parts' target='_blank'>$match[0]</a>";
                        $line = str_replace($match[0], $link, $line);
                        $og = $parts;
                    }
                } catch (Exception $e) {

                }
				$line = "<span class='lineNo'>$l</span><span class='$level $linecolor logSpan' data-text='$og'>$line</span>";
				array_push($newData, $line);
				$l++;

			}
			$data = $newData;
		//}
        /**
         * If the last entry in the array is an empty string lets remove it.
         */
        if(end($data) == "") {
            array_pop($data);
        }
        return json_encode(array("size" => $fsize, "file" => $this->log[$file], "data" => $data, "count"=>$count));
    }

    /**
     * This function will print out the required HTML/CSS/JS
     */
    public function generateGUI() {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Flex TV Log Viewer</title>

<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap.min.css">
<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap-theme.min.css">
<link rel="stylesheet" href="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.0/themes/smoothness/jquery-ui.css" />

<!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
<!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
<!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
<![endif]-->

<style type="text/css">
#grepKeyword, #settings {
    font-size: 80%;
}

.container {
	margin: 0;
}
#current {
	right: 15px;
	position: absolute;
}

.navbar-default .navbar-brand {
    color: #cecece !important;
}
.navbar-brand, .navbar-nav>li>a {
    text-shadow: 0 1px 0 rgba(0, 0, 0, 0.25) !important;
}
.navbar-default {
    background-image: -webkit-linear-gradient(top,#545454 0,#000000 100%)!important;
    background-image: -o-linear-gradient(top,#545454 0,#000000 100%)!important;
    background-image: -webkit-gradient(linear,left top,left bottom,from(#545454),to(#000000))!important;
    background-image: linear-gradient(to bottom,#545454 0,#000000 100%)!important;
}
.float {
    background: white;
    border-bottom: 1px solid black;
    padding: 10px 0 10px 0;
    margin: 0px;
    height: 30px;
    width: 100%;
    text-align: left;
}
.jsonParse {
	border: none;
	background: transparent;
	color: #3787ff;
	display: inline-block;
}
.contents {
    margin-top: 30px;
}
.WARN {
    color: #cecece;
	background-color: #ca9000 !important;
}

.DEBUG {
	color: #8dc3ff;
}

.ALERT {
    color: #000000;
    background-color: #ffffff !important;
}

.ERROR {
	background-color: #ab0006 !important;
    color: #cecece;
}
.INFO {
	background-color: #00229f !important;
    color: #cecece;
}
.grey {
	background-color: rgb(87, 87, 87);
}
.white {
	background-color: #000000;
}
.grey, .white {
	width: 100%;
	word-break:break-all;
	display: block;
	padding-left:15px;
}
.lineNo{
	float: left;
	margin: 0 15px;
}
.results {
    padding-bottom: 20px;
    font-family: monospace;
    font-size: small;
}
</style>

<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
<script src="//ajax.googleapis.com/ajax/libs/jqueryui/1.11.0/jquery-ui.min.js"></script>
<script type="text/javascript" src="./js/clipboard.min.js" defer></script>

<script type="text/javascript">
    /* <![CDATA[ */
    //Last know size of the file
    lastSize = 0;
    //Grep keyword
    grep = "";
    //Should the Grep be inverted?
    invert = 0;
    //Last known document height
    documentHeight = 0;
    //Last known scroll position
    scrollPosition = 0;
    //Should we scroll to the bottom?
    scroll = true;

    var count = 0;

    lastFile = window.location.hash != "" ? window.location.hash.substr(1) : "";
    console.log(lastFile);
    $(document).ready(function() {
    	$(document).on('click', '.jsonParse',function(e) {
    		e.preventDefault();
		    console.log("Form submitted.");
		    var data = $(this).data('json');
		    console.log("Data: ",data);
		    data = decodeURIComponent(data);
		    data = data.replace(/\+/g, ' ');
		    gotoUrl("http://jsonselector.com/process",{'rawjson':data});
	    });

	    $(document).on('dblclick', '.logSpan',function() {
		    var data = $(this).data('text');
		    console.log("Data: ",data);
		    var dummy = document.createElement("input");
		    document.body.appendChild(dummy);
		    dummy.setAttribute("id", "dummy_id");
		    document.getElementById("dummy_id").value=JSON.stringify(data);
		    dummy.select();
		    document.execCommand("copy");
		    document.body.removeChild(dummy);
		});

        // Setup the settings dialog
        $("#settings").dialog({
            modal : true,
            resizable : false,
            draggable : false,
            autoOpen : false,
            width : 590,
            height : 270,
            buttons : {
                Close : function() {
                    $(this).dialog("close");
                }
            },
            open : function(event, ui) {
                scrollToBottom();
            },
            close : function(event, ui) {
                grep = $("#grep").val();
                invert = $('#invert input:radio:checked').val();
                $("#results").text("");
                lastSize = 0;
                $("#grepspan").html("Grep keyword: \"" + grep + "\"");
                $("#invertspan").html("Inverted: " + (invert == 1 ? 'true' : 'false'));
            }
        });
        //Close the settings dialog after a user hits enter in the textarea
        $('#grep').keyup(function(e) {
            if (e.keyCode == 13) {
                $("#settings").dialog('close');
            }
        });
        //Focus on the textarea
        $("#grep").focus();
        //Settings button into a nice looking button with a theme
        //Settings button opens the settings dialog
        $("#grepKeyword").click(function() {
            $("#settings").dialog('open');
            $("#grepKeyword").removeClass('ui-state-focus');
        });
        $(".file").click(function(e) {
            $("#results").text("");
            lastSize = 0;
console.log(e);
            lastFile = $(e.target).text();
        });

        //Set up an interval for updating the log. Change updateTime in the PHPTail contstructor to change this
        setInterval("updateLog()", <?php echo $this->updateTime; ?>);
        //Some window scroll event to keep the menu at the top
        $(window).scroll(function(e) {
            if ($(window).scrollTop() > 0) {
                $('.float').css({
                    position : 'fixed',
                    top : '0',
                    left : 'auto'
                });
            } else {
                $('.float').css({
                    position : 'static'
                });
            }
        });
        //If window is resized should we scroll to the bottom?
        $(window).resize(function() {
            if (scroll) {
                scrollToBottom();
            }
        });
        //Handle if the window should be scrolled down or not
        $(window).scroll(function() {
            documentHeight = $(document).height();
            scrollPosition = $(window).height() + $(window).scrollTop();
            if (documentHeight <= scrollPosition) {
                scroll = true;
            } else {
                scroll = false;
            }
        });
        scrollToBottom();

    });
    //This function scrolls to the bottom
    function scrollToBottom() {
        $("html, body").animate({scrollTop: $(document).height()}, "fast");
    }
    //This function queries the server for updates.
    function updateLog() {
        $.getJSON('?ajax=1&file=' + lastFile + '&lastsize=' + lastSize + '&count=' + count + '&grep=' + grep + '&invert=' + invert + '&apiToken=<?php echo $this->apiToken; ?>', function(data) {
            lastSize = data.size;
            $("#current").text(data.file);
            var fileParts = data.file;
            count = data.count;
            fileParts = fileParts.split("/");
            fileParts = fileParts[fileParts.length - 1];
            $(".navbar-brand").text(fileParts);
            if (data.data != null) {
                $.each(data.data, function (key, value) {
                    $("#results").append('' + value + "\n");
                });
            }
            if (scroll) {
                scrollToBottom();
            }
        });
    }

    function gotoUrl(path, params, method) {
	    //Null check
	    method = method || "post"; // Set method to post by default if not specified.

	    // The rest of this code assumes you are not using a library.
	    // It can be made less wordy if you use one.
	    var form = document.createElement("form");
	    form.setAttribute("method", method);
	    form.setAttribute("action", path);
	    form.setAttribute("target","_blank");

	    //Fill the hidden form
	    if (typeof params === 'string') {
		    var hiddenField = document.createElement("input");
		    hiddenField.setAttribute("type", "hidden");
		    hiddenField.setAttribute("name", 'data');
		    hiddenField.setAttribute("value", params);
		    form.appendChild(hiddenField);
	    }
	    else {
		    for (var key in params) {
			    if (params.hasOwnProperty(key)) {
				    var hiddenField = document.createElement("input");
				    hiddenField.setAttribute("type", "hidden");
				    hiddenField.setAttribute("name", key);
				    if(typeof params[key] === 'object'){
					    hiddenField.setAttribute("value", JSON.stringify(params[key]));
				    }
				    else{
					    hiddenField.setAttribute("value", params[key]);
				    }
				    form.appendChild(hiddenField);
			    }
		    }
	    }

	    document.body.appendChild(form);
	    form.submit();
    }
    /* ]]> */
</script>
</head>
<body>
    <div class="navbar navbar-default navbar-fixed-top" role="navigation" <?php if ($this->noHeader) echo 'style="display:none"'?>>
        <div class="container">
            <div class="navbar-header">
                <a class="navbar-brand" href="#">Phlex.log</a>
            </div>
            <div class="collapse navbar-collapse">
                <ul class="nav navbar-nav">
                    <li class="dropdown">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown">Files<span class="caret"></span></a>
                        <ul class="dropdown-menu" role="menu">
                            <?php foreach ($this->log as $title => $f): ?>
                            <li><a class="file" href="#<?php echo $title;?>"><?php echo $title;?></a></li>
                            <?php endforeach;?>
                        </ul>
                    </li>
                    <li><a href="#" id="grepKeyword">Settings</a></li>
                    <li><span class="navbar-text" id="grepspan"></span></li>
                    <li><span class="navbar-text" id="invertspan"></span></li>
                </ul>
                <p class="navbar-text navbar-right" id="current"></p>
            </div>
        </div>
    </div>
    <div class="contents">
        <div id="results" class="results"></div>
        <div id="settings" title="PHPTail settings">
            <p>Grep keyword (return results that contain this keyword)</p>
            <input id="grep" type="text" value="" />
            <p>Should the grep keyword be inverted? (Return results that do NOT contain the keyword)</p>
            <div id="invert">
                <input type="radio" value="1" id="invert1" name="invert" /><label for="invert1">Yes</label>
                <input type="radio" value="0" id="invert2" name="invert" checked="checked" /><label for="invert2">No</label>
            </div>
        </div>
    </div>
<script src="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>
</body>
</html>
        <?php
    }
}


