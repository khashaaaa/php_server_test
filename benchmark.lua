local base_url = "http://localhost:8084"

local names = {"Emma", "Liam", "Olivia", "Noah", "Ava", "Oliver", "Isabella", "Lucas", "Sophia", "Mason"}
local domains = {"gmail.com", "yahoo.com", "hotmail.com", "outlook.com", "example.com"}

local request_counter = 0
local success_counter = 0
local error_counter = 0
local non_2xx_3xx = 0
local start_time = 0

local function generate_user_data()
    local name = names[math.random(#names)]
    local domain = domains[math.random(#domains)]
    
    request_counter = request_counter + 1
    local email = string.format("%s.%d@%s", string.lower(name), request_counter, domain)
    
    return string.format('{"name":"%s", "email":"%s"}', name, email)
end

function init(args)
    start_time = os.time()
    math.randomseed(os.time())
end

function request()
    local method = math.random(1, 100)
    local headers = { 
        ["Content-Type"] = "application/json",
        ["Accept"] = "application/json" 
    }
    
    if method <= 40 then
        return wrk.format("GET", base_url .. "/", headers)
    
    elseif method <= 80 then
        local payload = generate_user_data()
        headers["Content-Length"] = #payload
        return wrk.format("POST", base_url .. "/", headers, payload)
    
    elseif method <= 90 then
        local user_id = math.random(1, 100)
        local payload = generate_user_data()
        headers["Content-Length"] = #payload
        return wrk.format("PUT", base_url .. "/" .. user_id, headers, payload)
    
    else
        local user_id = math.random(1, 100)
        return wrk.format("DELETE", base_url .. "/" .. user_id, headers)
    end
end

function response(status, headers, body)
    if status >= 200 and status < 400 then
        success_counter = success_counter + 1
    else
        error_counter = error_counter + 1
    end
    
    if status < 200 or status >= 400 then
        non_2xx_3xx = non_2xx_3xx + 1
    end
end